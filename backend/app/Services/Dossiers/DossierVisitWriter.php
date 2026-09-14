<?php

namespace App\Services\Dossiers;

use App\Services\Clinics\ClinicCounts;
use App\Services\Directory\ClinicStaffLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DossierVisitWriter
{
    public function __construct(private DossierWrites $writes) {}

    public function save(Request $r, array $f, int $dossier, array $input, ?int $id): int
    {
        return $this->writes->once($r, $f, $input, "visit:$dossier:".($id ?? 'new'), function () use ($r, $f, $dossier, $input, $id) {
            $links = app(ClinicStaffLinks::class);
            $links->lockStaff(array_column($input['diagnoses'], 'diagnosing_staff_id'));
            $links->lockClinics(array_column($input['diagnoses'], 'clinic_id'));
            $d = $this->writes->dossier($f, $dossier);
            if ($id) {
                $old = DB::table('visits')->where('id', $id)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->where('patient_id', $d['patient_id'])->lockForUpdate()->first();
                abort_unless($old, 404);
                DossierWrites::version((array) $old, $input['lock_version']);
                if ($old->status !== 'draft' || $old->voided_at) {
                    DossierWrites::conflict('هذه الزيارة ليست مسودة قابلة للتعديل.');
                }
            } else {
                $old = null;
                if (DB::table('visits')->where('dossier_id', $dossier)->where('status', 'draft')->whereNull('voided_at')->exists()
                    || DB::table('dossier_section_progress')->where('dossier_id', $dossier)->whereNotNull('visit_id')->exists()) {
                    DossierWrites::conflict('توجد زيارة أولية محفوظة؛ اجلب أحدث نسخة لاستكمالها بدل إنشاء زيارة أخرى.');
                }
                if ($d['status'] !== 'draft') {
                    DossierWrites::conflict('إضافة الزيارة الأولية متاحة للإضبارة المسودة فقط.');
                }
            }
            if ($input['visit_date'] > $f['today']) {
                throw ValidationException::withMessages(['visit_date' => 'لا يمكن تسجيل زيارة فعلية مستقبلية.']);
            }
            if ((! $old || $old->visit_type_id != $input['visit_type_id']) && ! DB::table('visit_types')->where('id', $input['visit_type_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['visit_type_id' => 'اختر نوع زيارة فعالًا من الدليل.']);
            }
            $fields = Arr::only($input, ['visit_date', 'visit_type_id', 'is_referred']) + ['referring_hospital' => $input['is_referred'] ? $input['referring_hospital'] : null, 'referral_date' => $input['is_referred'] ? $input['referral_date'] : null, 'referral_reason' => $input['is_referred'] ? $input['referral_reason'] : null, 'updated_by' => $r->user()->id, 'updated_at' => now(), 'lock_version' => ($old?->lock_version ?? 0) + 1];
            if ($id) {
                DB::table('visits')->where('id', $id)->update($fields);
            } else {
                $id = DB::table('visits')->insertGetId($fields + ['facility_id' => $f['id'], 'patient_id' => $d['patient_id'], 'dossier_id' => $dossier, 'reporting_period_id' => null, 'visit_no' => 'V-'.Str::uuid(), 'client_request_id' => $input['request_id'], 'entered_by' => $r->user()->id, 'status' => 'draft', 'created_at' => now()]);
            }
            $rows = DB::table('visit_diagnoses')->where('visit_id', $id)->where('facility_id', $f['id'])->whereNull('voided_at')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($input['diagnoses'] as $index => $row) {
                $previous = ! empty($row['id']) ? $rows->get($row['id']) : null;
                if (! empty($row['id'])) {
                    abort_unless($previous, 404);
                    DossierWrites::version((array) $previous, $row['lock_version']);
                }
                if (! empty($row['remove'])) {
                    if (! $previous || empty($row['void_reason'])) {
                        throw ValidationException::withMessages(["diagnoses.$index.void_reason" => 'إزالة تشخيص محفوظ تحتاج سبب التصحيح.']);
                    }
                    $values = ['voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $row['void_reason'], 'updated_by' => $r->user()->id, 'updated_at' => now(), 'lock_version' => $previous->lock_version + 1];
                } else {
                    if (! empty($row['diagnosed_on']) && $row['diagnosed_on'] > $f['today']) {
                        throw ValidationException::withMessages(["diagnoses.$index.diagnosed_on" => 'تاريخ التشخيص لا يمكن أن يكون مستقبليًا.']);
                    }
                    if ((! $previous || $previous->diagnosis_id != $row['diagnosis_id']) && ! DB::table('diagnoses')->where('id', $row['diagnosis_id'])->where('is_active', true)->exists()) {
                        throw ValidationException::withMessages(["diagnoses.$index.diagnosis_id" => 'اختر تشخيصًا فعالًا.']);
                    }
                    $unchanged = $previous && $previous->clinic_id == $row['clinic_id'] && $previous->diagnosing_staff_id == $row['diagnosing_staff_id'];
                    if (! $unchanged && ! app(ClinicCounts::class)->currentDoctors(array_replace($f, ['today' => $input['visit_date']]))->where('c.id', $row['clinic_id'])->where('s.id', $row['diagnosing_staff_id'])->exists()) {
                        throw ValidationException::withMessages(["diagnoses.$index.diagnosing_staff_id" => 'اختر طبيبًا فعالًا من العيادة وله ارتباط يغطي تاريخ الزيارة.']);
                    }
                    $values = Arr::only($row, ['diagnosis_id', 'clinic_id', 'diagnosing_staff_id']) + ['diagnosed_on' => $row['diagnosed_on'] ?? null, 'updated_by' => $r->user()->id, 'updated_at' => now(), 'lock_version' => ($previous?->lock_version ?? 0) + 1];
                }
                if ($previous) {
                    $rowId = $previous->id;
                    DB::table('visit_diagnoses')->where('id', $rowId)->update($values);
                } else {
                    $rowId = DB::table('visit_diagnoses')->insertGetId($values + ['visit_id' => $id, 'facility_id' => $f['id'], 'reporting_period_id' => null, 'client_request_id' => (string) Str::uuid(), 'entered_by' => $r->user()->id, 'is_primary' => false, 'created_at' => now()]);
                }
                $this->writes->audit($r, $f, 'visit_diagnosis', $rowId, $previous ? (array) $previous : null, $values, ! empty($row['remove']) ? 'voided' : 'saved');
            }
            // Includes omitted saved rows: omission never deletes clinical history.
            $saved = DB::table('visit_diagnoses')->where('visit_id', $id)->whereNull('voided_at')->get();
            if ($saved->groupBy(fn ($row) => implode('|', [$row->diagnosis_id, $row->clinic_id, $row->diagnosing_staff_id, $row->diagnosed_on ?? '']))->contains(fn ($group) => $group->count() > 1)) {
                throw ValidationException::withMessages(['diagnoses' => 'لا تكرر التشخيص نفسه بالعيادة والطبيب والتاريخ أنفسها.']);
            }
            $this->writes->progress($r, $f, $dossier, 'visit', $saved->isEmpty() ? 'in_progress' : 'saved', $id);
            $this->writes->audit($r, $f, 'dossier_visit', $id, $old ? (array) $old : null, $fields);

            return $id;
        });
    }
}
