<?php

namespace App\Services\Dossiers;

use App\Services\Catalog\CatalogQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class OncologyQueries
{
    public const STATUSES = ['draft' => 'مسودة', 'active' => 'فعالة', 'paused' => 'متوقفة مؤقتًا', 'needs_review' => 'تحتاج مراجعة', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة'];

    public const MODALITIES = ['chemotherapy' => 'علاج كيميائي', 'immunotherapy' => 'علاج مناعي', 'targeted' => 'علاج موجّه', 'hormone' => 'علاج هرموني', 'radiotherapy' => 'علاج شعاعي', 'supportive' => 'علاج داعم', 'other' => 'آخر'];

    public const INTENTS = ['curative' => 'شفائي', 'palliative' => 'تلطيفي', 'neoadjuvant' => 'قبل العلاج الأساسي', 'adjuvant' => 'مساعد', 'maintenance' => 'استمراري', 'supportive' => 'داعم', 'other' => 'آخر'];

    public const SESSION_STATUSES = ['scheduled' => 'مجدولة', 'rescheduled' => 'أعيدت جدولتها', 'due' => 'مستحقة', 'completed' => 'أُعطيت فعليًا', 'missed' => 'لم يحضر', 'cancelled' => 'ملغاة', 'referred' => 'محالة'];

    public function readiness(array $f): Builder
    {
        return DB::query()->fromSub(app(DossierPathology::class)->summaries($f), 's')
            ->leftJoin('visit_pathologies as p', fn ($j) => $j->on('p.id', '=', 's.id')->where('s.kind', 0))
            ->leftJoin('visit_diagnostic_assessments as a', fn ($j) => $j->on('a.id', '=', 's.id')->where('s.kind', 1))
            ->leftJoin('visit_pathologies as e', 'e.id', '=', 'a.evidence_pathology_id')
            ->join('visits as v', 'v.id', '=', 's.visit_id')
            ->select('s.dossier_id', 's.disposition', 's.kind', 's.id', 's.visit_id', 's.fact_date')
            ->selectRaw("CONCAT(s.kind, ':', s.id, ':', COALESCE(p.lock_version,a.lock_version), ':', COALESCE(e.lock_version,0), ':', v.lock_version) AS basis_key")
            ->selectRaw('CASE WHEN s.kind=0 THEN p.id ELSE a.evidence_pathology_id END AS evidence_id');
    }

    public static function effectiveSql(): string
    {
        return "CASE WHEN p.status='active' AND (NOT (p.basis_key <=> ready.basis_key) OR ready.disposition IS NULL OR ready.disposition NOT IN ('pathology_confirmed','pathology_not_required')) THEN 'needs_review' ELSE p.status END";
    }

    public function plans(array $f): Builder
    {
        return DB::table('oncology_plans as p')->where('p.facility_id', $f['id'])
            ->join('oncology_plan_revisions as r', 'r.id', '=', 'p.current_revision_id')
            ->leftJoinSub($this->readiness($f), 'ready', 'ready.dossier_id', '=', 'p.dossier_id');
    }

    public function summary(array $f): Builder
    {
        $plans = $this->plans($f)->select('p.id', 'p.dossier_id', 'r.modality')->selectRaw(self::effectiveSql().' AS effective_status');
        $active = DB::query()->fromSub($plans, 'effective');

        return $active->select('dossier_id')->selectRaw("COUNT(*) AS plan_count, SUM(effective_status='active') AS active_count, SUM(effective_status='needs_review') AS review_count, GROUP_CONCAT(DISTINCT CASE WHEN effective_status='active' THEN modality END ORDER BY modality SEPARATOR ',') AS modalities")->groupBy('dossier_id');
    }

    public function scheduled(array $f): Builder
    {
        return DB::table('oncology_sessions as s')->leftJoinSub($this->plans($f)->select('p.id', 'p.current_revision_id')->selectRaw(self::effectiveSql().' AS effective_status'), 'plan', 'plan.id', '=', 's.plan_id')
            ->where('s.facility_id', $f['id'])->where(fn ($q) => $q->whereNull('s.plan_id')->orWhere(fn ($p) => $p->where('plan.effective_status', 'active')->whereColumn('s.revision_id', 'plan.current_revision_id')))->whereIn('s.status', ['scheduled', 'rescheduled'])
            ->whereNotExists(fn ($q) => $q->from('dose_sessions as d')->selectRaw('1')->whereColumn('d.oncology_session_id', 's.id')->whereNull('d.voided_at'));
    }

    public function decorate(Builder $q, array $f, array $input): void
    {
        $requested = array_filter(Arr::only($input, ['treatment_status', 'treatment_modality', 'dose_from', 'dose_to']));
        if (! ($f['capabilities']['treatment_view'] ?? false)) {
            abort_if((bool) $requested, 403);
            $q->selectRaw('NULL AS treatment_count, NULL AS active_treatment_count, NULL AS review_treatment_count, NULL AS treatment_modalities, NULL AS next_dose_on, NULL AS last_dose_on');

            return;
        }
        $q->leftJoinSub($this->summary($f), 'treatment', 'treatment.dossier_id', '=', 'd.id')
            ->selectRaw('COALESCE(treatment.plan_count,0) AS treatment_count, COALESCE(treatment.active_count,0) AS active_treatment_count, COALESCE(treatment.review_count,0) AS review_treatment_count, treatment.modalities AS treatment_modalities')
            ->selectSub($this->scheduled($f)->whereColumn('s.dossier_id', 'd.id')->where('s.planned_on', '>=', $f['today'])->selectRaw('MIN(s.planned_on)'), 'next_dose_on')
            ->selectSub(DB::table('dose_sessions as dose')->join('visits as v', 'v.id', '=', 'dose.visit_id')->where('dose.facility_id', $f['id'])->whereColumn('v.dossier_id', 'd.id')->whereNull('dose.voided_at')->whereNull('v.voided_at')->whereIn('v.status', ['draft', 'complete'])->where('dose.administered_on', '<=', $f['today'])->selectRaw('MAX(dose.administered_on)'), 'last_dose_on');
        if (! empty($input['treatment_status']) || ! empty($input['treatment_modality'])) {
            $p = $this->plans($f)->whereColumn('p.dossier_id', 'd.id')->selectRaw('1');
            if (! empty($input['treatment_status'])) {
                $p->whereRaw(self::effectiveSql().' = ?', [$input['treatment_status']]);
            }
            if (! empty($input['treatment_modality'])) {
                $p->where('r.modality', $input['treatment_modality']);
            }
            $q->whereExists($p);
        }
        if (! empty($input['dose_from']) || ! empty($input['dose_to'])) {
            $due = $this->scheduled($f)->whereColumn('s.dossier_id', 'd.id')->selectRaw('1');
            if (! empty($input['dose_from'])) {
                $due->where('s.planned_on', '>=', $input['dose_from']);
            }
            if (! empty($input['dose_to'])) {
                $due->where('s.planned_on', '<=', $input['dose_to']);
            }
            $q->whereExists($due);
        }
    }

    public function listing(array $f, int $dossier, array $input): array
    {
        app(DossierWrites::class)->dossier($f, $dossier, false);
        $q = $this->plans($f)->where('p.dossier_id', $dossier);
        if (! empty($input['status'])) {
            $q->whereRaw(self::effectiveSql().' = ?', [$input['status']]);
        }
        $page = $q->orderByDesc('p.id')->paginate($input['per_page'] ?? 10, ['p.*', 'r.protocol_name', 'r.modality', 'r.intent', 'r.starts_on', 'r.revision_number', DB::raw(self::effectiveSql().' AS effective_status')], 'page', $input['page'] ?? 1);

        return ['data' => $page->items(), 'meta' => CatalogQueries::meta($page), 'readiness' => $this->readiness($f)->where('s.dossier_id', $dossier)->first(), 'next_dose' => $this->scheduled($f)->where('s.dossier_id', $dossier)->where('s.planned_on', '>=', $f['today'])->orderBy('s.planned_on')->orderBy('s.id')->first(['s.id', 's.planned_on', 's.plan_id'])];
    }

    public function plan(array $f, int $dossier, int $id): array
    {
        app(DossierWrites::class)->dossier($f, $dossier, false);
        $row = $this->plans($f)->where('p.dossier_id', $dossier)->where('p.id', $id)->select('p.*')->selectRaw(self::effectiveSql().' AS effective_status')->first();
        abort_unless($row, 404);
        $revisions = DB::table('oncology_plan_revisions as r')->leftJoin('clinics as c', 'c.id', '=', 'r.clinic_id')->leftJoin('staff as s', 's.id', '=', 'r.doctor_id')->where('r.plan_id', $id)->orderByDesc('r.revision_number')->get(['r.*', 'c.name_ar as clinic_name', 's.full_name as doctor_name']);
        $items = DB::table('oncology_regimen_items')->whereIn('revision_id', $revisions->pluck('id'))->orderBy('display_order')->get()->groupBy('revision_id');

        return (array) $row + ['unresolved_session_count' => DB::table('oncology_sessions')->where('plan_id', $id)->where('revision_id', '<>', $row->current_revision_id)->whereIn('status', ['scheduled', 'rescheduled'])->count(), 'revisions' => $revisions->map(fn ($r) => (array) $r + ['items' => ($items[$r->id] ?? collect())->all()])->all()];
    }

    public static function voidedDoseSql(): string
    {
        return 'EXISTS (SELECT 1 FROM dose_sessions AS history WHERE history.oncology_session_id = s.id AND history.voided_at IS NOT NULL) AS has_voided_dose';
    }

    public function sessions(array $f, int $dossier, array $input): array
    {
        app(DossierWrites::class)->dossier($f, $dossier, false);
        $q = DB::table('oncology_sessions as s')->leftJoinSub($this->plans($f)->select('p.id', 'p.plan_number', 'p.current_revision_id', 'p.lock_version')->selectRaw(self::effectiveSql().' AS effective_status'), 'p', 'p.id', '=', 's.plan_id')->leftJoin('dose_sessions as dose', fn ($j) => $j->on('dose.oncology_session_id', '=', 's.id')->whereNull('dose.voided_at'))->where('s.facility_id', $f['id'])->where('s.dossier_id', $dossier);
        foreach (['plan_id', 'status'] as $key) {
            if (! empty($input[$key])) {
                $q->where('s.'.$key, $input[$key]);
            }
        }
        $page = $q->orderBy('s.planned_on')->orderBy('s.id')->paginate($input['per_page'] ?? 10, ['s.*', 'p.plan_number', 'p.effective_status', 'p.current_revision_id', 'p.lock_version as plan_lock_version', 'dose.id as dose_id', 'dose.visit_id', DB::raw(self::voidedDoseSql())], 'page', $input['page'] ?? 1);
        $page->getCollection()->each(function ($row) {
            $row->has_voided_dose = (bool) $row->has_voided_dose;
        });

        return ['data' => $page->items(), 'meta' => CatalogQueries::meta($page)];
    }

    public function doses(array $f, int $dossier, int $visit): array
    {
        app(DossierPathology::class)->visit($f, $dossier, $visit);
        $doses = DB::table('dose_sessions as d')->leftJoin('oncology_sessions as s', 's.id', '=', 'd.oncology_session_id')->leftJoin('oncology_plan_revisions as r', 'r.id', '=', 'd.plan_revision_id')->leftJoin('oncology_plans as p', 'p.id', '=', 's.plan_id')->where('d.visit_id', $visit)->where('d.facility_id', $f['id'])->orderBy('d.id')->get(['d.*', 'r.clinic_id', 's.lock_version as session_lock_version', 's.planned_on', 's.revision_id as session_revision_id', 'p.current_revision_id', 'p.lock_version as plan_lock_version']);
        $items = DB::table('dose_session_items')->whereIn('dose_session_id', $doses->pluck('id'))->orderBy('id')->get()->groupBy('dose_session_id');
        $dispensed = DB::table('visit_medications as m')->leftJoin('dose_sessions as d', 'd.id', '=', 'm.dose_session_id')->leftJoin('oncology_plan_revisions as r', 'r.id', '=', 'd.plan_revision_id')->where('m.visit_id', $visit)->where('m.facility_id', $f['id'])->orderBy('m.id')->get(['m.*', 'r.clinic_id', 'd.voided_at as parent_voided_at']);

        return ['doses' => $doses->map(fn ($d) => (array) $d + ['items' => ($items[$d->id] ?? collect())->all()])->all(), 'dispensed' => $dispensed->all()];
    }
}
