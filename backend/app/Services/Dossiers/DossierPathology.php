<?php

namespace App\Services\Dossiers;

use App\Services\Catalog\CatalogQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DossierPathology
{
    public const DISPOSITIONS = ['not_assessed' => 'غير مقيّم', 'pathology_required' => 'التشريح المرضي مطلوب', 'pathology_pending' => 'بانتظار النتيجة', 'pathology_confirmed' => 'نتيجة التشريح المرضي متوفرة', 'pathology_not_required' => 'لا يتطلب تشريحًا مرضيًا', 'referred_out' => 'إحالة إلى جهة خارجية', 'unavailable' => 'التشريح المرضي غير متاح', 'cancelled' => 'أُلغيت متابعة التشريح المرضي'];

    public const STATUSES = ['requested' => 'مطلوب', 'specimen_collected' => 'جُمعت العينة', 'pending_result' => 'بانتظار النتيجة', 'completed' => 'نتيجة متوفرة', 'unavailable' => 'غير متاح', 'cancelled' => 'ملغى'];

    private const FIELDS = ['source', 'status', 'report_number', 'external_organization', 'specimen_type', 'anatomical_site', 'procedure_event_id', 'requested_on', 'collected_on', 'result_on', 'conclusion', 'note', 'unavailable_reason', 'clinic_id', 'doctor_id'];

    private const ASSESSMENT_FIELDS = ['disposition', 'assessed_on', 'note', 'required_reason', 'not_required_reason', 'follow_up', 'clinic_id', 'doctor_id', 'evidence_pathology_id'];

    public function __construct(private DossierWrites $writes, private DossierClinicalContext $context) {}

    public function visit(array $f, int $dossier, int $visit, bool $lock = false): object
    {
        $d = $this->writes->dossier($f, $dossier, $lock);
        $q = app(DossierQueries::class)->actualVisits($f)->where('v.dossier_id', $dossier)->where('v.patient_id', $d['patient_id'])->where('v.id', $visit);
        $v = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($v, 404);

        return $v;
    }

    private function cases(array $f): Builder
    {
        return DB::table('visit_pathologies as p')->join('visits as v', 'v.id', '=', 'p.visit_id')
            ->where('p.facility_id', $f['id'])->where('v.facility_id', $f['id'])->whereIn('v.status', ['draft', 'complete'])->whereNull('v.voided_at')->where('v.visit_date', '<=', $f['today']);
    }

    private function assessments(array $f): Builder
    {
        return DB::table('visit_diagnostic_assessments as a')->join('visits as v', 'v.id', '=', 'a.visit_id')
            ->leftJoin('visit_pathologies as evidence', 'evidence.id', '=', 'a.evidence_pathology_id')
            ->leftJoin('visits as ev', 'ev.id', '=', 'evidence.visit_id')
            ->where('a.facility_id', $f['id'])->where('v.facility_id', $f['id'])->whereIn('v.status', ['draft', 'complete'])->whereNull('v.voided_at')->where('v.visit_date', '<=', $f['today']);
    }

    private function effectiveSql(): string
    {
        // A historical decision is retained, but withdrawn evidence can never claim readiness.
        return "CASE WHEN a.disposition = 'pathology_confirmed' AND NOT (evidence.status <=> 'completed' AND evidence.voided_at IS NULL AND ev.voided_at IS NULL AND ev.status IN ('draft','complete') AND ev.visit_date <= ? AND evidence.result_on <= ?) THEN 'not_assessed' WHEN a.disposition = 'referred_out' AND NOT EXISTS (SELECT 1 FROM visit_outcomes o JOIN visit_results r ON r.id=o.result_id WHERE o.visit_id=a.visit_id AND o.facility_id=a.facility_id AND o.voided_at IS NULL AND r.code='DOS-REFER') THEN 'not_assessed' ELSE a.disposition END";
    }

    public function summaries(array $f): Builder
    {
        $cases = $this->cases($f)->whereNull('p.voided_at')->selectRaw("p.dossier_id, p.visit_id, p.id, COALESCE(p.result_on,p.collected_on,p.requested_on,v.visit_date) AS fact_date, CASE p.status WHEN 'completed' THEN 'pathology_confirmed' WHEN 'requested' THEN 'pathology_required' WHEN 'specimen_collected' THEN 'pathology_pending' WHEN 'pending_result' THEN 'pathology_pending' ELSE p.status END AS disposition");
        $ranked = DB::query()->fromSub($cases, 'facts')->select('facts.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY dossier_id ORDER BY fact_date DESC, visit_id DESC, id DESC) AS fact_rank');

        return DB::query()->fromSub($ranked, 'ranked')->where('fact_rank', 1);
    }

    public function assessment(array $f, int $dossier, int $visit): ?array
    {
        $this->visit($f, $dossier, $visit);
        $row = $this->assessments($f)->where('a.visit_id', $visit)->select('a.*')->selectRaw($this->effectiveSql().' AS effective_disposition', [$f['today'], $f['today']])->first();

        return $row ? (array) $row + ['needs_review' => $row->disposition !== $row->effective_disposition] : null;
    }

    public function listing(array $f, int $dossier, array $input = [], ?int $visit = null): array
    {
        $this->writes->dossier($f, $dossier, false);
        if ($visit) {
            $this->visit($f, $dossier, $visit);
        }
        $q = $this->cases($f)->leftJoin('clinics as clinic', 'clinic.id', '=', 'p.clinic_id')->leftJoin('staff as doctor', 'doctor.id', '=', 'p.doctor_id')->where('p.dossier_id', $dossier)->when($visit, fn ($q) => $q->where('p.visit_id', $visit));
        $q->when(! empty($input['status']), fn ($q) => $q->where('p.status', $input['status']))
            ->when(! empty($input['source']), fn ($q) => $q->where('p.source', $input['source']));
        if (! empty($input['search'])) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($input['search'])).'%';
            $q->where(fn ($q) => $q->whereRaw("p.report_number LIKE ? ESCAPE '!'", [$like])->orWhereRaw("p.conclusion LIKE ? ESCAPE '!'", [$like]));
        }
        if (! empty($input['evidence_only'])) {
            $q->whereNull('p.voided_at')->where('p.status', 'completed');
        }
        $page = $q->orderByRaw('COALESCE(p.result_on,p.collected_on,p.requested_on,v.visit_date) DESC')->orderByDesc('p.id')->paginate($input['per_page'] ?? 10, ['p.*', 'v.visit_no', 'v.visit_date', 'v.status as visit_status', 'clinic.name_ar as clinic_name', 'doctor.full_name as doctor_name'], 'page', $input['page'] ?? 1);
        $files = $this->attachmentMetadata($f, array_column($page->items(), 'id'));
        $rows = array_map(fn ($row) => (array) $row + ['name_ar' => ($row->report_number ?? '#'.$row->id).' · '.$row->visit_no.' · '.self::STATUSES[$row->status], 'attachments' => $files[$row->id] ?? [], 'capabilities' => ['update' => ! $row->voided_at && ($f['capabilities']['pathology_update'] ?? false), 'void' => ! $row->voided_at && ($f['capabilities']['pathology_void'] ?? false)]], $page->items());

        return ['data' => $rows, 'meta' => CatalogQueries::meta($page)];
    }

    public function show(array $f, int $dossier, int $visit, int $id): array
    {
        $this->visit($f, $dossier, $visit);
        $row = $this->cases($f)->leftJoin('clinics as clinic', 'clinic.id', '=', 'p.clinic_id')->leftJoin('staff as doctor', 'doctor.id', '=', 'p.doctor_id')->where('p.id', $id)->where('p.visit_id', $visit)->where('p.dossier_id', $dossier)->first(['p.*', 'v.visit_no', 'v.visit_date', 'clinic.name_ar as clinic_name', 'doctor.full_name as doctor_name']);
        abort_unless($row, 404);

        return (array) $row + ['attachments' => $this->attachmentMetadata($f, [$id])[$id] ?? [], 'capabilities' => ['update' => ! $row->voided_at && ($f['capabilities']['pathology_update'] ?? false), 'void' => ! $row->voided_at && ($f['capabilities']['pathology_void'] ?? false)]];
    }

    public function attachmentMetadata(array $f, array $ids): array
    {
        if (! ($f['capabilities']['attachments_view'] ?? false) || ! $ids) {
            return [];
        }

        return DB::table('pathology_attachments as l')->join('visit_attachments as a', 'a.id', '=', 'l.attachment_id')->where('l.facility_id', $f['id'])->whereIn('l.pathology_id', $ids)
            ->orderBy('a.id')->get(['l.pathology_id', 'a.id', 'a.visit_id', 'a.title', 'a.original_filename', 'a.size', 'a.voided_at', 'a.void_reason'])->groupBy('pathology_id')->map(fn ($rows) => $rows->all())->all();
    }

    public function dates(array $f, array $data, string $visitDate): void
    {
        $last = null;
        foreach (['requested_on', 'collected_on', 'result_on', 'assessed_on'] as $field) {
            $value = $data[$field] ?? null;
            if (! $value) {
                continue;
            }
            if ($value > $f['today']) {
                throw ValidationException::withMessages([$field => 'تاريخ الواقعة لا يمكن أن يكون بعد اليوم بحسب توقيت المنشأة.']);
            }
            if (($field === 'assessed_on' || ($data['source'] ?? null) === 'internal') && $value < $visitDate) {
                throw ValidationException::withMessages([$field => 'يجب أن يكون هذا التاريخ في يوم الزيارة المصدر أو بعده.']);
            }
            if ($last && $value < $last) {
                throw ValidationException::withMessages([$field => 'يجب ترتيب التواريخ المعروفة: الطلب ثم جمع العينة ثم النتيجة.']);
            }
            $last = $value;
        }
    }

    private function requiredValue(array $fields, string $field): void
    {
        if (blank($fields[$field])) {
            throw ValidationException::withMessages([$field => 'هذا الحقل مطلوب للحالة أو القرار المختار.']);
        }
    }

    private function normalizePathology(array $fields, ?object $old): array
    {
        if ($old?->status === 'completed' && $fields['status'] !== 'completed') {
            throw ValidationException::withMessages(['status' => 'لا يمكن خفض حالة تقرير مكتمل. صحّح نتيجته مع التدقيق أو ألغِ التقرير بسبب صريح ثم أضف التقرير الصحيح.']);
        }
        if ($fields['source'] === 'external') {
            $this->requiredValue($fields, 'external_organization');
        } else {
            $fields['external_organization'] = null;
        }
        if (in_array($fields['status'], ['unavailable', 'cancelled'])) {
            $this->requiredValue($fields, 'unavailable_reason');
        } else {
            $fields['unavailable_reason'] = null;
        }
        if ($fields['status'] === 'completed') {
            $this->requiredValue($fields, 'result_on');
            $this->requiredValue($fields, 'conclusion');
        } else {
            $fields['result_on'] = $fields['conclusion'] = null;
        }

        return $fields;
    }

    private function normalizeAssessment(array $fields): array
    {
        if ($fields['disposition'] !== 'pathology_not_required') {
            $fields['not_required_reason'] = null;
        } else {
            $this->requiredValue($fields, 'not_required_reason');
        }
        if (in_array($fields['disposition'], ['not_assessed', 'pathology_not_required'])) {
            $fields['required_reason'] = null;
        } elseif ($fields['disposition'] === 'pathology_required') {
            $this->requiredValue($fields, 'required_reason');
        }
        if ($fields['disposition'] !== 'pathology_confirmed') {
            $fields['evidence_pathology_id'] = null;
        }

        return $fields;
    }

    private function responsible(array $f, object $v, array $data, ?object $old): void
    {
        $message = ($data['source'] ?? null) === 'external' ? 'اختر الطبيب المراجع ضمن المشفى والعيادة.' : 'اختر الطبيب المنظم والعيادة.';
        if (empty($data['clinic_id']) || empty($data['doctor_id'])) {
            throw ValidationException::withMessages(['doctor_id' => $message]);
        }
        if (! $old || $old->clinic_id != $data['clinic_id'] || $old->doctor_id != $data['doctor_id']) {
            $this->context->check($f, $data['clinic_id'], $data['doctor_id'], $v->visit_date, 'doctor_id', false);
        }
    }

    private function persist(Request $r, array $f, object $v, string $table, ?object $old, array $fields, array $data): int
    {
        $fields += ['updated_by' => $r->user()->id, 'updated_at' => now(), 'lock_version' => ($old?->lock_version ?? 0) + 1];
        if ($old) {
            $id = $old->id;
            DB::table($table)->where('id', $id)->update($fields);
        } else {
            $id = DB::table($table)->insertGetId($fields + ['facility_id' => $f['id'], 'patient_id' => $v->patient_id, 'dossier_id' => $v->dossier_id, 'visit_id' => $v->id, 'client_request_id' => $data['request_id'], 'entered_by' => $r->user()->id, 'created_at' => now()]);
        }
        $this->writes->audit($r, $f, $table, $id, $old ? (array) $old : null, (array) DB::table($table)->where('id', $id)->first());

        return $id;
    }

    public function save(Request $r, array $f, int $dossier, int $visit, array $data, ?int $id = null, bool $void = false): int
    {
        return $this->writes->once($r, $f, $data, 'pathology:'.$dossier.':'.$visit.':'.($id ?? 'new').':'.($void ? 'void' : 'save'), function () use ($r, $f, $dossier, $visit, $data, $id, $void) {
            $this->context->lock([array_filter([$data['doctor_id'] ?? null]), array_filter([$data['clinic_id'] ?? null])]);
            $v = $this->visit($f, $dossier, $visit, true);
            $old = $id ? DB::table('visit_pathologies')->where('id', $id)->where('visit_id', $visit)->where('facility_id', $f['id'])->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($old, 404);
                DossierWrites::version((array) $old, $data['lock_version']);
                if ($old->voided_at) {
                    DossierWrites::conflict('التقرير ملغى؛ يبقى في التاريخ ولا يمكن تعديله.');
                }
            }
            if ($void) {
                return $this->persist($r, $f, $v, 'visit_pathologies', $old, ['voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $data['void_reason']], $data);
            }
            $fields = array_replace(array_fill_keys(self::FIELDS, null), $old ? Arr::only((array) $old, self::FIELDS) : [], Arr::only($data, self::FIELDS));
            $fields = $this->normalizePathology($fields, $old);
            $this->dates($f, $fields, $v->visit_date);
            $this->responsible($f, $v, $fields, $old);
            if ($fields['procedure_event_id'] && ! DB::table('visit_procedures')->where('id', $fields['procedure_event_id'])->where('visit_id', $visit)->where('facility_id', $f['id'])->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['procedure_event_id' => 'اختر إجراءً محفوظًا غير ملغى من هذه الزيارة.']);
            }
            if (! empty($data['attachment_ids'])) {
                if (! ($f['capabilities']['attachments_view'] ?? false)) {
                    abort(403);
                }
                $count = DB::table('visit_attachments')->whereIn('id', $data['attachment_ids'])->where('visit_id', $visit)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->whereNull('voided_at')->count();
                if ($count !== count($data['attachment_ids'])) {
                    throw ValidationException::withMessages(['attachment_ids' => 'اختر مرفقات مكتملة غير ملغاة تخص هذه الزيارة.']);
                }
            }
            $saved = $this->persist($r, $f, $v, 'visit_pathologies', $old, $fields, $data);
            foreach ($data['attachment_ids'] ?? [] as $attachment) {
                $added = DB::table('pathology_attachments')->insertOrIgnore(['pathology_id' => $saved, 'attachment_id' => $attachment, 'visit_id' => $visit, 'facility_id' => $f['id']]);
                if ($added) {
                    $this->writes->audit($r, $f, 'visit_pathologies', $saved, null, ['supporting_attachment_id' => $attachment], 'attachment_linked');
                }
            }

            return $saved;
        });
    }

    public function saveAssessment(Request $r, array $f, int $dossier, int $visit, array $data): int
    {
        return $this->writes->once($r, $f, $data, "assessment:$dossier:$visit", function () use ($r, $f, $dossier, $visit, $data) {
            $this->context->lock([array_filter([$data['doctor_id'] ?? null]), array_filter([$data['clinic_id'] ?? null])]);
            $v = $this->visit($f, $dossier, $visit, true);
            $old = DB::table('visit_diagnostic_assessments')->where('visit_id', $visit)->where('facility_id', $f['id'])->lockForUpdate()->first();
            DossierWrites::version(['lock_version' => $old?->lock_version ?? 0], $data['lock_version']);
            $fields = array_replace(array_fill_keys(self::ASSESSMENT_FIELDS, null), $old ? Arr::only((array) $old, self::ASSESSMENT_FIELDS) : [], Arr::only($data, self::ASSESSMENT_FIELDS));
            $fields = $this->normalizeAssessment($fields);
            $this->dates($f, $fields, $v->visit_date);
            $this->responsible($f, $v, $fields, $old);
            if ($fields['disposition'] === 'pathology_confirmed') {
                if (! $this->cases($f)->where('p.dossier_id', $dossier)->where('p.id', $fields['evidence_pathology_id'])->where('p.status', 'completed')->whereNull('p.voided_at')->where('p.result_on', '<=', $f['today'])->exists()) {
                    throw ValidationException::withMessages(['evidence_pathology_id' => 'التأكيد يحتاج تقريرًا مكتملًا غير ملغى ضمن هذه البطاقة والمنشأة.']);
                }
            } else {
                $fields['evidence_pathology_id'] = null;
            }
            if ($fields['disposition'] === 'referred_out' && ! DB::table('visit_outcomes as o')->join('visit_results as r', 'r.id', '=', 'o.result_id')->where('o.visit_id', $visit)->where('o.facility_id', $f['id'])->whereNull('o.voided_at')->where('r.code', 'DOS-REFER')->whereNotNull('o.outgoing_referral_reason')->exists()) {
                throw ValidationException::withMessages(['disposition' => 'سجّل الإحالة الخارجية وسبب عدم توفر الفحص في نتيجة هذه الزيارة أولًا.']);
            }

            return $this->persist($r, $f, $v, 'visit_diagnostic_assessments', $old, $fields, $data);
        });
    }
}
