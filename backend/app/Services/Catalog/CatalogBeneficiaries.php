<?php

namespace App\Services\Catalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogBeneficiaries
{
    public const DEFINITION = 'مرضى فريدون لكل نوع ومعرّف ضمن المنشأة: أحداث منفذة حتى تاريخ المنشأة وغير ملغاة في زيارات مكتملة؛ تشمل الإجراءات أيضًا أحداث متلقي الدم غير الملغاة لمريض معروف، مع اشتراط اكتمال الزيارة إن ارتبطت بها. للأدوية يُحسب الصرف بتاريخ dispensed_on وجلسات الجرعة عبر dose_sessions حيث الإلغاء على الجلسة لا على البند. تُستبعد المسودات والملغى والمستقبل، ويُزال التكرار بين المصدرين.';

    /** One shared relation for the count, patients list and reports. */
    private function events(array $facility, bool $includeEventDetails = false): Builder
    {
        $visit = function (string $kind) use ($facility, $includeEventDetails) {
            $query = DB::table('visit_'.$kind.'s as e')->join('visits as v', function ($join) {
                $join->on('v.id', '=', 'e.visit_id')->on('v.facility_id', '=', 'e.facility_id');
            })->where('e.facility_id', $facility['id'])->where('v.status', 'complete')->whereNull('v.voided_at')
                ->whereNull('e.voided_at')->where('e.performed_on', '<=', $facility['today'])
                ->selectRaw('? as kind', [$kind])->addSelect('e.'.$kind.'_id as item_id', 'v.patient_id');

            return $includeEventDetails ? $query->selectRaw('? as source', ['visit_'.$kind])->addSelect('e.id as event_id', 'e.performed_on', 'v.visit_no') : $query;
        };
        $blood = DB::table('blood_recipient_procedures as e')->join('blood_transfusions as b', 'b.id', '=', 'e.blood_transfusion_id')
            ->leftJoin('visits as v', function ($join) {
                $join->on('v.id', '=', 'b.visit_id')->on('v.facility_id', '=', 'b.facility_id')->on('v.patient_id', '=', 'b.patient_id');
            })
            ->where('b.facility_id', $facility['id'])->whereNull('b.voided_at')->whereNotNull('b.patient_id')
            ->where('e.performed_on', '<=', $facility['today'])->where('b.transfused_on', '<=', $facility['today'])
            ->where(fn ($q) => $q->whereNull('b.visit_id')->orWhere(fn ($v) => $v->where('v.status', 'complete')->whereNull('v.voided_at')))
            ->selectRaw('? as kind', ['procedure'])->addSelect('e.procedure_id as item_id', 'b.patient_id');
        if ($includeEventDetails) {
            $blood->selectRaw('? as source', ['blood_procedure'])->addSelect('e.id as event_id', 'e.performed_on', 'v.visit_no');
        }
        $dispensed = DB::table('visit_medications as e')->join('visits as v', function ($join) {
            $join->on('v.id', '=', 'e.visit_id')->on('v.facility_id', '=', 'e.facility_id');
        })->where('e.facility_id', $facility['id'])->where('v.status', 'complete')->whereNull('v.voided_at')
            ->whereNull('e.voided_at')->whereNotNull('e.medication_id')->where('e.dispensed_on', '<=', $facility['today'])
            ->selectRaw('? as kind', ['medication'])->addSelect('e.medication_id as item_id', 'v.patient_id');
        if ($includeEventDetails) {
            $dispensed->selectRaw('? as source', ['visit_medication'])->addSelect('e.id as event_id', 'e.dispensed_on as performed_on', 'v.visit_no');
        }
        $doses = DB::table('dose_session_items as e')->join('dose_sessions as s', 's.id', '=', 'e.dose_session_id')
            ->join('visits as v', function ($join) {
                $join->on('v.id', '=', 's.visit_id')->on('v.facility_id', '=', 's.facility_id');
            })->where('s.facility_id', $facility['id'])->where('v.status', 'complete')->whereNull('v.voided_at')
            ->whereNull('s.voided_at')->whereNotNull('e.medication_id')->where('s.administered_on', '<=', $facility['today'])
            ->selectRaw('? as kind', ['medication'])->addSelect('e.medication_id as item_id', 'v.patient_id');
        if ($includeEventDetails) {
            $doses->selectRaw('? as source', ['dose_session_item'])->addSelect('e.id as event_id', 's.administered_on as performed_on', 'v.visit_no');
        }

        // Keep the original narrow, deduplicated relation for list counts/reports.
        // Only the presentation endpoint materializes the individual event identities.
        $union = $includeEventDetails
            ? $visit('service')->unionAll($visit('procedure'))->unionAll($blood)->unionAll($dispensed)->unionAll($doses)
            : $visit('service')->union($visit('procedure'))->union($blood)->union($dispensed)->union($doses);

        return DB::query()->fromSub($union, 'benefit');
    }

    public function facts(array $facility): Builder
    {
        return $this->events($facility);
    }

    public function presentations(array $facility, string $kind, int $id, array $filters): array
    {
        $query = $this->events($facility, true)->join('patients as p', 'p.id', '=', 'benefit.patient_id')->where('kind', $kind)->where('item_id', $id);
        $search = trim(preg_replace('/\s+/u', ' ', $filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('p.patient_code', 'like', $like)->orWhere('p.first_name', 'like', $like)->orWhere('p.family_name', 'like', $like)
                // Match the displayed full name, including legacy whitespace, with a bound literal LIKE value.
                ->orWhereRaw("REGEXP_REPLACE(CONCAT_WS(' ', p.first_name, p.family_name), '[[:space:]]+', ' ') LIKE ?", [$like]));
        }
        if (! empty($filters['from'])) {
            $query->where('performed_on', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('performed_on', '<=', $filters['to']);
        }

        return DB::transaction(function () use ($query, $filters) {
            $patients = (clone $query)->distinct()->count('benefit.patient_id');
            $sort = $filters['sort'] ?? 'performed_on';
            $direction = $filters['direction'] ?? 'desc';
            if ($sort === 'patient_name') {
                $query->orderBy('p.first_name', $direction)->orderBy('p.family_name', $direction);
            } else {
                $query->orderBy($sort, $direction);
            }
            $page = $query->orderBy('source')->orderBy('event_id')->select('benefit.source', 'benefit.event_id', 'benefit.performed_on', 'benefit.visit_no', 'p.patient_code', 'p.first_name', 'p.family_name')
                ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
            $rows = $page->getCollection()->map(fn ($row) => ['key' => $row->source.':'.$row->event_id, 'source' => $row->source, 'event_id' => (int) $row->event_id,
                'patient_code' => $row->patient_code, 'patient_name' => $row->first_name.' '.$row->family_name, 'performed_on' => $row->performed_on, 'visit_no' => $row->visit_no])->all();

            return ['data' => $rows, 'meta' => CatalogQueries::meta($page), 'totals' => ['unique_patients' => $patients, 'presentations' => $page->total()]];
        });
    }

    public function patients(array $facility, string $kind, int $id, array $filters): array
    {
        $ids = $this->facts($facility)->where('kind', $kind)->where('item_id', $id)->select('patient_id');
        $query = DB::table('patients')->whereIn('id', $ids)->select('id', 'patient_code', 'first_name', 'family_name');
        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('patient_code', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('family_name', 'like', $like));
        }
        $page = $query->orderBy('patient_code')->orderBy('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $page->items(), 'meta' => CatalogQueries::meta($page)];
    }

    public function reportPatients(array $facility, array $rows): Collection
    {
        if ($rows === []) {
            return collect();
        }
        $query = $this->events($facility, true)->join('patients as p', 'p.id', '=', 'benefit.patient_id');
        $query->where(function ($q) use ($rows) {
            foreach (collect($rows)->groupBy('kind') as $kind => $items) {
                $q->orWhere(fn ($w) => $w->where('kind', $kind)->whereIn('item_id', $items->pluck('id')));
            }
        });

        return $query->groupBy('benefit.kind', 'benefit.item_id', 'p.id', 'p.patient_code', 'p.first_name', 'p.family_name')
            ->select('benefit.kind', 'benefit.item_id as owner_id', 'p.id as patient_id', 'p.patient_code', 'p.first_name', 'p.family_name')
            ->selectRaw('COUNT(*) as visit_count')->selectRaw('MAX(benefit.performed_on) as last_on')
            ->orderBy('p.patient_code')->orderBy('p.id')
            ->limit(config('clinics.export_patient_limit') + 1)->get();
    }
}
