<?php

namespace App\Services\Catalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CatalogBeneficiaries
{
    public const DEFINITION = 'مرضى فريدون لكل نوع ومعرّف ضمن المنشأة: أحداث منفذة حتى تاريخ المنشأة وغير ملغاة في زيارات مكتملة؛ تشمل الإجراءات أيضًا أحداث متلقي الدم غير الملغاة لمريض معروف، مع اشتراط اكتمال الزيارة إن ارتبطت بها. تُستبعد المسودات والملغى والمستقبل، ويُزال التكرار بين المصدرين.';

    /** One shared relation for the count, patients list and reports; no patient data loaded per directory row. */
    public function facts(array $facility): Builder
    {
        $visit = function (string $kind) use ($facility) {
            return DB::table('visit_'.$kind.'s as e')->join('visits as v', function ($join) {
                $join->on('v.id', '=', 'e.visit_id')->on('v.facility_id', '=', 'e.facility_id');
            })->where('e.facility_id', $facility['id'])->where('v.status', 'complete')->whereNull('v.voided_at')
                ->whereNull('e.voided_at')->where('e.performed_on', '<=', $facility['today'])
                ->selectRaw('? as kind', [$kind])->addSelect('e.'.$kind.'_id as item_id', 'v.patient_id');
        };
        $blood = DB::table('blood_recipient_procedures as e')->join('blood_transfusions as b', 'b.id', '=', 'e.blood_transfusion_id')
            ->leftJoin('visits as v', function ($join) {
                $join->on('v.id', '=', 'b.visit_id')->on('v.facility_id', '=', 'b.facility_id')->on('v.patient_id', '=', 'b.patient_id');
            })
            ->where('b.facility_id', $facility['id'])->whereNull('b.voided_at')->whereNotNull('b.patient_id')
            ->where('e.performed_on', '<=', $facility['today'])->where('b.transfused_on', '<=', $facility['today'])
            ->where(fn ($q) => $q->whereNull('b.visit_id')->orWhere(fn ($v) => $v->where('v.status', 'complete')->whereNull('v.voided_at')))
            ->selectRaw('? as kind', ['procedure'])->addSelect('e.procedure_id as item_id', 'b.patient_id');

        return DB::query()->fromSub($visit('service')->union($visit('procedure'))->union($blood), 'benefit');
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
}
