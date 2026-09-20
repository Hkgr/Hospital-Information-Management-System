<?php

namespace App\Services\Dossiers;

use App\Services\Catalog\CatalogQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DossierQueries
{
    public function actualVisits(array $facility): Builder
    {
        return DB::table('visits as v')->where('v.facility_id', $facility['id'])->whereNotNull('v.dossier_id')->whereIn('v.status', ['draft', 'complete'])
            ->whereNull('v.voided_at')->where('v.visit_date', '<=', $facility['today']);
    }

    private function dossiers(array $f): Builder
    {
        return DB::table('patient_dossiers as d')->join('patients as p', 'p.id', '=', 'd.patient_id')
            ->where('d.facility_id', $f['id'])->whereIn('d.status', ['draft', 'active']);
    }

    public function listing(array $f, array $input): array
    {
        return DB::transaction(function () use ($f, $input) {
            $visits = $this->actualVisits($f)->whereColumn('v.dossier_id', 'd.id')->whereColumn('v.patient_id', 'd.patient_id');
            $latest = (clone $visits)->orderByDesc('v.visit_date')->orderByDesc('v.id')->limit(1);
            $q = $this->dossiers($f)->select('d.id', 'd.patient_id as card_id', 'p.patient_code as code', 'd.status', 'd.opening_date', 'd.is_oncology', 'p.patient_code', 'p.mother_name', 'p.gender', 'p.birth_date', 'p.birth_date_accuracy', 'p.phone', 'p.paper_file_number')
                ->selectRaw("CONCAT_WS(' ', p.first_name, p.family_name) as patient_name")
                ->selectSub((clone $visits)->selectRaw('COUNT(*)'), 'visit_count')
                ->selectSub(DB::table('visits as saved')->whereColumn('saved.dossier_id', 'd.id')->selectRaw('COUNT(*)'), 'saved_visit_count')
                ->selectSub((clone $visits)->join('visit_procedures as procedure_events', fn ($j) => $j->on('procedure_events.visit_id', '=', 'v.id')->on('procedure_events.facility_id', '=', 'v.facility_id'))
                    ->whereNull('procedure_events.voided_at')->where('procedure_events.performed_on', '<=', $f['today'])->selectRaw('COUNT(*)'), 'procedure_count')
                ->selectSub((clone $latest)->select('v.id'), 'latest_visit_id')
                ->selectSub((clone $latest)->select('v.visit_date'), 'latest_visit_date')
                ->selectSub((clone $latest)->select('v.status'), 'latest_visit_status');
            $q->leftJoinSub(app(DossierPathology::class)->summaries($f), 'pathology_summary', 'pathology_summary.dossier_id', '=', 'd.id')
                ->addSelect('pathology_summary.disposition as pathology_status', 'pathology_summary.visit_id as pathology_visit_id');
            if (! empty($input['pathology_status'])) {
                $q->whereRaw("COALESCE(pathology_summary.disposition, 'not_assessed') = ?", [$input['pathology_status']]);
            }
            if (($input['status'] ?? 'all') !== 'all') {
                $q->where('d.status', $input['status']);
            }
            $search = trim(preg_replace('/\s+/u', ' ', $input['search'] ?? ''));
            if ($search !== '') {
                // Explicit escape character keeps %, _, and backslashes literal regardless of SQL mode.
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $q->where(fn ($w) => $w->whereExists(fn ($a) => $a->selectRaw('1')->from('patient_dossiers as legacy')->whereColumn('legacy.patient_id', 'p.id')->whereRaw("legacy.code LIKE ? ESCAPE '!'", [$like]))
                    ->orWhereRaw("p.patient_code LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("REGEXP_REPLACE(CONCAT_WS(' ', p.first_name, p.family_name), '[[:space:]]+', ' ') LIKE ? ESCAPE '!'", [$like]));
            }
            if (! empty($input['oncology'])) {
                $q->where('d.is_oncology', $input['oncology'] === 'yes');
            }
            if (! empty($input['visits'])) {
                $input['visits'] === 'with' ? $q->whereExists((clone $visits)->selectRaw('1')) : $q->whereNotExists((clone $visits)->selectRaw('1'));
            }
            if (! empty($input['from'])) {
                $q->where('d.opening_date', '>=', $input['from']);
            }
            if (! empty($input['to'])) {
                $q->where('d.opening_date', '<=', $input['to']);
            }
            $sort = ['code' => 'p.patient_code', 'patient_name' => 'patient_name', 'opening_date' => 'd.opening_date', 'visit_count' => 'visit_count'][$input['sort'] ?? 'opening_date'];
            $page = $q->orderBy($sort, $input['direction'] ?? 'desc')->orderByDesc('d.id')->paginate($input['per_page'] ?? 20, ['*'], 'page', $input['page'] ?? 1);
            $ids = $page->getCollection()->pluck('latest_visit_id')->filter()->all();
            $diagnoses = $this->diagnoses($f, $ids);
            $actions = app(DossierWorkflowActions::class)->forDossiers($f, $page->items());
            $page->setCollection($page->getCollection()->map(function ($row) use ($diagnoses, $actions) {
                return ['id' => (int) $row->id, 'card_id' => (int) $row->card_id, 'code' => $row->code, 'legacy_without_visits' => (int) $row->saved_visit_count === 0, 'status' => $row->status, 'opening_date' => $row->opening_date, 'is_oncology' => (bool) $row->is_oncology,
                    'patient_code' => $row->patient_code, 'patient_name' => $row->patient_name, 'mother_name' => $row->mother_name, 'gender' => $row->gender, 'birth_date' => $row->birth_date, 'birth_date_accuracy' => $row->birth_date_accuracy, 'phone' => $row->phone, 'paper_file_number' => $row->paper_file_number,
                    'visit_count' => (int) $row->visit_count, 'procedure_count' => (int) $row->procedure_count, 'workflow' => $actions[$row->id]['workflow'],
                    'pathology_status' => $row->pathology_status ?? 'not_assessed', 'pathology_visit_id' => $row->pathology_visit_id ? (int) $row->pathology_visit_id : null,
                    'latest_visit_id' => $row->latest_visit_id ? (int) $row->latest_visit_id : null, 'latest_visit_date' => $row->latest_visit_date, 'latest_visit_status' => $row->latest_visit_status, 'diagnoses' => $diagnoses[$row->latest_visit_id] ?? []];
            }));

            return ['data' => $page->items(), 'meta' => CatalogQueries::meta($page), 'totals' => ['dossiers' => $page->total()]];
        });
    }

    private function diagnoses(array $f, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return DB::table('visit_diagnoses as e')->join('diagnoses as n', 'n.id', '=', 'e.diagnosis_id')
            ->join('staff as s', 's.id', '=', 'e.diagnosing_staff_id')
            ->leftJoin('clinics as c', fn ($j) => $j->on('c.id', '=', 'e.clinic_id')->on('c.facility_id', '=', 'e.facility_id'))
            ->where('e.facility_id', $f['id'])->whereIn('e.visit_id', $ids)->whereNull('e.voided_at')
            ->where(fn ($q) => $q->whereNull('e.diagnosed_on')->orWhere('e.diagnosed_on', '<=', $f['today']))
            ->orderBy('e.id')->get(['e.id', 'e.visit_id', 'n.code', 'n.name_ar as name', 'e.diagnosed_on', 'c.name_ar as clinic', 's.full_name as doctor'])
            ->groupBy('visit_id')->map(fn ($rows) => $rows->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name, 'diagnosed_on' => $r->diagnosed_on, 'clinic' => $r->clinic, 'doctor' => $r->doctor])->all())->all();
    }

    public function detail(array $f, int $id): array
    {
        return DB::transaction(function () use ($f, $id) {
            $d = $this->dossiers($f)->where('d.id', $id)->select('d.*')->first();
            abort_unless($d, 404);
            $patient = DB::table('patients as p')->leftJoin('governorates as g', 'g.id', '=', 'p.governorate_id')
                ->leftJoin('cities as c', fn ($j) => $j->on('c.id', '=', 'p.city_id')->on('c.governorate_id', '=', 'p.governorate_id'))
                ->where('p.id', $d->patient_id)->first(['p.patient_code', 'p.first_name', 'p.family_name', 'p.father_name', 'p.mother_name', 'p.birth_date', 'p.birth_date_accuracy', 'p.gender', 'p.phone', 'p.alt_phone', 'p.paper_file_number', 'g.name_ar as governorate', 'c.name_ar as city', 'p.address_line', 'p.displacement_status']);
            $v = $this->actualVisits($f)->where('v.dossier_id', $id);
            $latest = (clone $v)->orderByDesc('v.visit_date')->orderByDesc('v.id')->value('v.id');

            return ['id' => (int) $d->id, 'card_id' => (int) $d->patient_id, 'facility_id' => (int) $d->facility_id, 'code' => $patient->patient_code, 'legacy_without_visits' => ! DB::table('visits')->where('dossier_id', $id)->exists(), 'status' => $d->status, 'opening_date' => $d->opening_date,
                'disability_text' => $d->disability_text, 'clinical_history' => $d->clinical_history, 'is_oncology' => (bool) $d->is_oncology,
                'oncology' => $d->is_oncology ? ['previous_examinations' => $d->previous_examinations, 'medication_source' => $d->medication_source, 'other_organization' => $d->other_organization,
                    'selections' => DB::table('dossier_oncology_selections')->where('dossier_id', $id)->where('facility_id', $f['id'])->where('is_active', true)->orderBy('selection_group')->orderBy('code')->get(['selection_group', 'code'])->all()] : null,
                'patient' => (array) $patient, 'visit_count' => $v->count(), 'latest_visit' => $latest ? $this->visit($f, $id, (int) $latest) : null,
                'pathology_summary' => app(DossierPathology::class)->summaries($f)->where('dossier_id', $id)->first(['disposition', 'visit_id', 'fact_date']),
                'workflow' => app(DossierWorkflowActions::class)->forDossiers($f, [$d])[$id]['workflow']];
        });
    }

    public function visits(array $f, int $id, array $input): array
    {
        abort_unless($this->dossiers($f)->where('d.id', $id)->exists(), 404);
        $q = $this->actualVisits($f)->where('v.dossier_id', $id);
        if (($input['status'] ?? 'all') !== 'all') {
            $q->where('v.status', $input['status']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (! empty($input[$key])) {
                $q->where('v.visit_date', $op, $input[$key]);
            }
        }
        if (! empty($input['search'])) {
            $q->whereRaw("v.visit_no LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($input['search'])).'%']);
        }
        $sort = in_array($input['sort'] ?? '', ['visit_no', 'visit_date', 'status']) ? $input['sort'] : 'visit_date';
        $p = $q->orderBy('v.'.$sort, $input['direction'] ?? 'desc')->orderByDesc('v.id')
            ->paginate($input['per_page'] ?? 10, ['v.id', 'v.visit_no', 'v.visit_date', 'v.status'], 'page', $input['page'] ?? 1);

        return ['data' => $p->items(), 'meta' => CatalogQueries::meta($p)];
    }

    public function visit(array $f, int $id, int $visit): array
    {
        abort_unless($this->dossiers($f)->where('d.id', $id)->exists(), 404);
        $v = $this->actualVisits($f)->where('v.dossier_id', $id)->where('v.id', $visit)
            ->leftJoin('clinics as c', fn ($j) => $j->on('c.id', '=', 'v.clinic_id')->on('c.facility_id', '=', 'v.facility_id'))
            ->leftJoin('staff as s', 's.id', '=', 'v.attending_staff_id')->first(['v.id', 'v.visit_no', 'v.visit_date', 'v.status', 'v.is_referred', 'v.referring_hospital', 'v.referral_date', 'v.referral_reason', 'c.name_ar as visit_clinic', 's.full_name as attending_doctor']);
        abort_unless($v, 404);
        $result = (array) $v;
        $result['is_referred'] = (bool) $result['is_referred'];
        $result['diagnoses'] = $this->diagnoses($f, [$visit])[$visit] ?? [];
        foreach (['services' => ['services', 'service_id', 'performed_on'], 'procedures' => ['procedures', 'procedure_id', 'performed_on'], 'outcomes' => ['visit_results', 'result_id', 'outcome_on']] as $kind => [$catalog, $key, $date]) {
            $result[$kind] = DB::table('visit_'.$kind.' as e')->join($catalog.' as n', 'n.id', '=', 'e.'.$key)
                ->where('e.visit_id', $visit)->where('e.facility_id', $f['id'])->whereNull('e.voided_at')->where('e.'.$date, '<=', $f['today'])
                ->orderBy('e.'.$date)->orderBy('e.id')->get(['e.id', 'n.code', 'n.name_ar as name', 'e.'.$date.' as date', ...($kind === 'outcomes' ? [] : ['e.quantity'])])->all();
        }
        $result['medications'] = DB::table('visit_medications as e')->where('e.visit_id', $visit)->where('e.facility_id', $f['id'])->whereNull('e.voided_at')->where('e.dispensed_on', '<=', $f['today'])
            ->orderBy('e.dispensed_on')->orderBy('e.id')->get(['e.id', 'e.medication_name_snapshot as name', 'e.dispensed_on as date', 'e.dose_text', 'e.quantity', 'e.quantity_unit'])->all();
        $result['administered_medications'] = DB::table('dose_sessions as s')->join('dose_session_items as e', 'e.dose_session_id', '=', 's.id')->where('s.visit_id', $visit)->where('s.facility_id', $f['id'])->whereNull('s.voided_at')->where('s.administered_on', '<=', $f['today'])
            ->orderBy('s.administered_on')->orderBy('e.id')->get(['e.id', 'e.medication_name_snapshot as name', 's.administered_on as date', 'e.dose_text', 'e.quantity', 'e.quantity_unit'])->all();

        $result['clinical'] = app(DossierVisitSections::class)->read($f, $visit);
        $result['diagnostic_assessment'] = app(DossierPathology::class)->assessment($f, $id, $visit);

        return $result;
    }
}
