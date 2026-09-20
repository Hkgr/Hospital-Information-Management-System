<?php

namespace App\Services\Dossiers;

use Database\Seeders\DossierOutcomeSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DossierFinalizer
{
    public function save(Request $r, array $f, int $dossier, int $visit, array $data, bool $complete): int
    {
        $contexts = app(DossierClinicalContext::class);
        $targets = $contexts->targets($visit, $f, $data);
        $writes = app(DossierWrites::class);

        return $writes->once($r, $f, $data, ($complete ? 'complete' : 'review').":$dossier:$visit", function () use ($r, $f, $dossier, $visit, $data, $complete, $contexts, $targets, $writes) {
            $contexts->lock($targets);
            $v = app(DossierClinicalWriter::class)->visit($f, $dossier, $visit);
            DossierWrites::version((array) $v, $data['lock_version']);
            $d = $writes->dossier($f, $dossier);
            if (DB::table('visit_attachment_uploads')->where('visit_id', $visit)->where('state', 'pending')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['attachments' => 'انتظر اكتمال رفع الملفات أو ألغِ المحاولات المعلقة قبل المراجعة.']);
            }
            if ($complete) {
                app(DossierAccess::class)->facility($r->user(), $f['id'], 'visits.complete');
                DossierWrites::version($d, $data['dossier_lock_version']);
                if ($d['status'] === 'draft') {
                    app(DossierAccess::class)->facility($r->user(), $f['id'], 'finalize');
                    $initial = app(DossierWorkflowActions::class)->forDossiers($f, [$d])[$dossier]['initial_visit'];
                    if (! $initial || $initial->id !== $visit) {
                        DossierWrites::conflict('يجب إكمال أول زيارة مسجلة ضمن البطاقة المحددة مع تفعيل بطاقة المريض.');
                    }
                }
                $progress = DB::table('dossier_section_progress')->where('dossier_id', $dossier)->where('facility_id', $f['id'])->where(fn ($q) => $q->whereNull('visit_id')->orWhere('visit_id', $visit))->get()->keyBy('section');
                foreach (['personal', 'medical', 'visit', 'clinical', 'medications', 'attachments'] as $section) {
                    if (($progress->get($section)?->state ?? 'not_started') !== 'saved') {
                        throw ValidationException::withMessages([$section => 'احفظ هذا القسم وراجعه صراحة قبل الإكمال؛ يمكن تأكيد الأقسام الاختيارية فارغة.']);
                    }
                }
                if ($v->visit_date > $f['today']) {
                    throw ValidationException::withMessages(['visit_date' => 'لا يمكن إكمال زيارة بتاريخ مستقبلي.']);
                }
                if (! DB::table('visit_diagnoses')->where('visit_id', $visit)->whereNull('voided_at')->exists()) {
                    throw ValidationException::withMessages(['diagnoses' => 'يجب حفظ تشخيص واحد على الأقل.']);
                }
                $outcome = DB::table('visit_outcomes as o')->join('visit_results as r', 'r.id', '=', 'o.result_id')->where('o.visit_id', $visit)->whereNull('o.voided_at')->first(['r.code', 'o.outcome_on']);
                if (! $outcome || ! array_key_exists($outcome->code, DossierOutcomeSeeder::OUTCOMES) || $outcome->outcome_on > $f['today']) {
                    throw ValidationException::withMessages(['outcome' => 'احفظ نتيجة معتمدة واحدة بتاريخ غير مستقبلي قبل الإكمال.']);
                }
                $contexts->retained($f, $visit, $v->visit_date);
                $contexts->check($f, $data['clinic_id'], $data['attending_staff_id'], $v->visit_date, 'attending_staff_id', false);
                if ($d['status'] === 'draft') {
                    $fields = ['status' => 'active', 'lock_version' => $d['lock_version'] + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()];
                    DB::table('patient_dossiers')->where('id', $dossier)->update($fields);
                    $writes->audit($r, $f, 'patient_dossier', $dossier, $d, $fields, 'activated');
                }
                $fields = ['status' => 'complete', 'clinic_id' => $data['clinic_id'], 'attending_staff_id' => $data['attending_staff_id']];
            } else {
                if (! $f['capabilities']['visits_update'] && ! $f['capabilities']['visits_complete']) {
                    abort(403);
                }
                $writes->progress($r, $f, $dossier, 'attachments', 'saved', $visit);
                $fields = [];
            }
            $fields += ['phase_three' => true, 'lock_version' => $v->lock_version + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()];
            DB::table('visits')->where('id', $visit)->update($fields);
            $writes->audit($r, $f, 'dossier_visit', $visit, (array) $v, $fields, $complete ? 'completed' : 'reviewed');

            return $visit;
        });
    }
}
