<?php

namespace App\Services\Dossiers;

use App\Services\BloodBank\BloodEventWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DossierClinicalWriter
{
    public function __construct(private DossierWrites $writes, private DossierClinicalContext $context) {}

    public function visit(array $f, int $dossier, int $visit): object
    {
        $d = $this->writes->dossier($f, $dossier);
        $v = DB::table('visits')->where('id', $visit)->where('dossier_id', $dossier)->where('patient_id', $d['patient_id'])->where('facility_id', $f['id'])->lockForUpdate()->first();
        abort_unless($v, 404);
        if ($v->status !== 'draft' || $v->voided_at) {
            DossierWrites::conflict('الزيارة المكتملة أو الملغاة للقراءة فقط؛ لم تتغير مسودتك.');
        }
        if ($v->visit_date > $f['today']) {
            throw ValidationException::withMessages(['visit_date' => 'لا يمكن حفظ زيارة بتاريخ مستقبلي.']);
        }

        return $v;
    }

    public function save(Request $r, array $f, int $dossier, int $visit, string $section, array $data): int
    {
        $targets = $this->context->targets($visit, $f, $data);

        return $this->writes->once($r, $f, $data, "$section:$dossier:$visit", function () use ($r, $f, $dossier, $visit, $section, $data, $targets) {
            $this->context->lock($targets);
            $v = $this->visit($f, $dossier, $visit);
            DossierWrites::version((array) $v, $data['lock_version']);
            if ($section === 'clinical') {
                foreach (['services' => ['service_id', 'performed_by'], 'procedures' => ['procedure_id', 'specialist_id']] as $kind => [$catalog,$doctor]) {
                    foreach ($data[$kind] as $i => $row) {
                        $table = 'visit_'.$kind;
                        $old = $this->row($table, $row, ['visit_id' => $visit, 'facility_id' => $f['id']]);
                        $bloodLink = $kind === 'services' ? ($old->blood_bank_event_id ?? null) : null;
                        if ($bloodLink && (! empty($row['remove']) || $row['catalog_id'] != $old->$catalog)) {
                            throw ValidationException::withMessages(["$kind.$i.catalog_id" => 'الخدمة مرتبطة باستفادة دم محفوظة؛ لا يمكن حذفها أو استبدالها وإخفاء الواقعة. يلزم تصحيح الاستفادة وفق قواعدها.']);
                        }
                        if (! empty($row['remove'])) {
                            $this->void($r, $f, $table, $old, $row, "$kind.$i");

                            continue;
                        }
                        $manual = trim((string) ($row['manual_doctor_name'] ?? '')) ?: null;
                        $doctorId = $row['doctor_id'] ?? null;
                        if ($manual && $doctorId) {
                            throw ValidationException::withMessages(["$kind.$i.doctor_id" => 'اختر طبيبًا من الدليل أو اكتب اسمه يدويًا، ولا تجمع الخيارين.']);
                        }
                        if (! $manual && ! $doctorId && empty($row['remove'])) {
                            throw ValidationException::withMessages(["$kind.$i.doctor_id" => 'اختر طبيبًا من القائمة، أو اكتب الاسم عند اختيار غير ذلك.']);
                        }
                        $unchanged = $old && $old->clinic_id == $row['clinic_id'] && $doctorId == $old->$doctor && $manual === ($old->manual_doctor_name ?? null);
                        $this->context->check($f, $row['clinic_id'], $doctorId, $v->visit_date, "$kind.$i.doctor_id", (bool) $unchanged, $manual);
                        $location = [];
                        if ($kind === 'procedures') {
                            // Doctor/name corrections must not reinterpret a saved event using today's directory.
                            // Only an explicit procedure or clinic correction adopts current location rules;
                            // persist() audits both the old snapshot and the replacement under the row lock.
                            if (! $old || $row['catalog_id'] != $old->$catalog || $row['clinic_id'] != $old->clinic_id) {
                                $location = app(ProcedureLocation::class)->catalog($f, $row['clinic_id'], $row['catalog_id'], "$kind.$i.clinic_id");
                            } else {
                                app(ProcedureLocation::class)->check($f, $row['clinic_id'], $old->execution_location_snapshot, "$kind.$i.clinic_id");
                            }
                        }
                        if ((! $old || $row['catalog_id'] != $old->$catalog) && ! DB::table($kind)->where('id', $row['catalog_id'])->where('is_active', true)->whereNull('archived_at')->exists()) {
                            throw ValidationException::withMessages(["$kind.$i.catalog_id" => 'اختر عنصرًا فعالًا غير مؤرشف من الدليل.']);
                        }
                        $fields = $location + [$catalog => $row['catalog_id'], 'clinic_id' => $row['clinic_id'], $doctor => $doctorId, 'manual_doctor_name' => $manual, 'note' => $row['note'] ?? null, 'performed_on' => $v->visit_date, 'dossier_managed' => true];
                        if ($kind === 'services') {
                            $bloodService = DB::table('services')->where('id', $row['catalog_id'])->where('code', 'SER-4-001')->exists();
                            if (! empty($row['blood_benefit']) && ! $bloodService) {
                                throw ValidationException::withMessages(["$kind.$i.blood_benefit" => 'تسجيل الاستفادة متاح لخدمة نقل الدم فقط.']);
                            }
                            if ($bloodService && (! $old || $row['catalog_id'] != $old->$catalog) && empty($row['blood_benefit'])) {
                                throw ValidationException::withMessages(["$kind.$i.blood_benefit" => 'أكمل نموذج تسجيل الاستفادة قبل حفظ خدمة نقل الدم.']);
                            }
                            if (! empty($row['blood_benefit'])) {
                                if (($row['blood_benefit']['occurred_on'] ?? null) !== $v->visit_date || (int) ($row['blood_benefit']['clinic_id'] ?? 0) !== (int) $row['clinic_id'] || (int) ($row['blood_benefit']['responsible_staff_id'] ?? 0) !== (int) $doctorId || $manual) {
                                    throw ValidationException::withMessages(["$kind.$i.blood_benefit" => 'تاريخ الاستفادة وعيادتها وطبيبها يجب أن يطابقوا سياق الخدمة والزيارة.']);
                                }
                                try {
                                    $fields['blood_bank_event_id'] = app(BloodEventWriter::class)->forVisitService($r, $f, $v, $row['catalog_id'], $row['blood_benefit'], $bloodLink);
                                } catch (ValidationException $e) {
                                    throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["$kind.$i.blood_benefit.$key" => $messages])->all());
                                }
                            } elseif ($bloodLink) {
                                $event = DB::table('blood_bank_events')->where('id', $bloodLink)->where('facility_id', $f['id'])->first();
                                if (! $event || $event->voided_at || $event->occurred_on !== $v->visit_date || (int) $event->clinic_id !== (int) $row['clinic_id'] || (int) $event->responsible_staff_id !== (int) $doctorId || $manual) {
                                    throw ValidationException::withMessages(["$kind.$i.blood_benefit" => 'راجع الاستفادة المرتبطة وصحح سياقها مع الخدمة قبل الحفظ.']);
                                }
                            }
                        }
                        $this->persist($r, $f, $table, $old, $fields, ['visit_id' => $visit, 'facility_id' => $f['id'], 'reporting_period_id' => null, 'client_request_id' => (string) Str::uuid()]);
                    }
                }
            } else {
                if ($data['prescription'] !== null) {
                    $this->prescription($r, $f, $v, $data['prescription']);
                }
                if ($data['outcome'] !== null) {
                    $this->outcome($r, $f, $v, $data['outcome']);
                }
            }
            $fields = ['phase_three' => true, 'lock_version' => $v->lock_version + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()];
            DB::table('visits')->where('id', $visit)->update($fields);
            $this->writes->progress($r, $f, $dossier, $section, 'saved', $visit, ! empty($data['unchanged']));
            $this->writes->audit($r, $f, 'dossier_visit', $visit, (array) $v, $fields);

            return $visit;
        });
    }

    private function row(string $table, array $row, array $scope): ?object
    {
        if (empty($row['id'])) {
            return null;
        }
        $old = DB::table($table)->where($scope)->where('id', $row['id'])->whereNull('voided_at')->lockForUpdate()->first();
        abort_unless($old, 404);
        DossierWrites::version((array) $old, $row['lock_version']);

        return $old;
    }

    private function persist(Request $r, array $f, string $table, ?object $old, array $fields, array $create): int
    {
        $fields += ['updated_by' => $r->user()->id, 'updated_at' => now(), 'lock_version' => ($old?->lock_version ?? 0) + 1];
        if ($old) {
            $id = $old->id;
            DB::table($table)->where('id', $id)->update($fields);
        } else {
            $id = DB::table($table)->insertGetId($fields + $create + ['entered_by' => $r->user()->id, 'created_at' => now()]);
        }
        $this->writes->audit($r, $f, $table, $id, $old ? (array) $old : null, $fields + $create);

        return $id;
    }

    private function void(Request $r, array $f, string $table, ?object $old, array $row, string $field): void
    {
        if (! $old || trim($row['void_reason'] ?? '') === '') {
            throw ValidationException::withMessages(["$field.void_reason" => 'الإلغاء يحتاج السجل المحفوظ ونسخته وسبب التصحيح.']);
        }
        $this->persist($r, $f, $table, $old, ['voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $row['void_reason']], []);
    }

    private function prescription(Request $r, array $f, object $v, array $row): void
    {
        $kind = $row['kind'] ?? 'unlinked';
        $old = $this->row('visit_prescriptions', $row, ['visit_id' => $v->id, 'facility_id' => $f['id']]);
        if ($old && $old->kind !== $kind) {
            throw ValidationException::withMessages(['prescription.kind' => 'لا يمكن تغيير نوع الوصفة المحفوظة.']);
        }
        if (! empty($row['remove'])) {
            $this->void($r, $f, 'visit_prescriptions', $old, $row, 'prescription');

            return;
        }
        if ($row['prescribed_on'] > $f['today']) {
            throw ValidationException::withMessages(['prescription.prescribed_on' => 'تاريخ الوصفة لا يمكن أن يكون مستقبليًا.']);
        }
        if (! $old || $old->prescribing_clinic_id != $row['prescribing_clinic_id'] || $old->prescribing_staff_id != $row['prescribing_staff_id']) {
            $this->context->check($f, $row['prescribing_clinic_id'], $row['prescribing_staff_id'], $v->visit_date, 'prescription.prescribing_staff_id', false);
        }
        $funding = app(MedicationSources::class)->selected((int) ($row['funding_source_id'] ?? 0), 'id', $old?->funding_source_id, 'prescription.funding_source_id')->id;
        $reason = $kind === 'outside' ? trim((string) ($row['unavailable_reason'] ?? '')) : null;
        if ($kind === 'outside' && $reason === '') {
            throw ValidationException::withMessages(['prescription.unavailable_reason' => 'اذكر سبب عدم تواجد هذه الأدوية في المشفى.']);
        }
        $id = $this->persist($r, $f, 'visit_prescriptions', $old, Arr::only($row, ['prescribing_clinic_id', 'prescribing_staff_id', 'prescribed_on']) + ['kind' => $kind, 'note' => $row['note'] ?? null, 'funding_source_id' => $funding, 'unavailable_reason' => $reason ?: null], ['visit_id' => $v->id, 'facility_id' => $f['id'], 'client_request_id' => (string) Str::uuid()]);
        foreach ($row['items'] as $i => $item) {
            $prior = $this->row('visit_prescription_items', $item, ['prescription_id' => $id, 'facility_id' => $f['id']]);
            if (! empty($item['remove'])) {
                $this->void($r, $f, 'visit_prescription_items', $prior, $item, "prescription.items.$i");

                continue;
            }
            $fields = ['medication_id' => $item['medication_id'], 'note' => $item['note'] ?? null, 'display_order' => $item['display_order']];
            if (! $prior || $prior->medication_id != $item['medication_id']) {
                $med = DB::table('medications')->where('id', $item['medication_id'])->where('is_active', true)->whereNull('archived_at')->first();
                if (! $med) {
                    throw ValidationException::withMessages(["prescription.items.$i.medication_id" => 'اختر دواء فعالًا من الدليل.']);
                }
                $fields += ['medication_code_snapshot' => $med->code, 'medication_name_snapshot' => $med->name_ar];
            }
            $this->persist($r, $f, 'visit_prescription_items', $prior, $fields, ['prescription_id' => $id, 'facility_id' => $f['id']]);
        }
        if (! DB::table('visit_prescription_items')->where('prescription_id', $id)->whereNull('voided_at')->exists()) {
            throw ValidationException::withMessages(['prescription.items' => 'أضف دواء للوصفة أو ألغِ الوصفة صراحة. يمكن حفظ الزيارة دون وصفة.']);
        }
    }

    private function outcome(Request $r, array $f, object $v, array $row): void
    {
        $old = $this->row('visit_outcomes', $row, ['visit_id' => $v->id, 'facility_id' => $f['id']]);
        if (! $old && DB::table('visit_outcomes')->where('visit_id', $v->id)->whereNull('voided_at')->exists()) {
            DossierWrites::conflict('توجد نتيجة محفوظة؛ اجلب أحدث نسخة للمراجعة.');
        }
        if (! empty($row['remove'])) {
            $this->void($r, $f, 'visit_outcomes', $old, $row, 'outcome');

            return;
        }
        if ($row['outcome_on'] > $f['today'] || ($row['outgoing_referral_date'] ?? '') > $f['today']) {
            throw ValidationException::withMessages(['outcome.outcome_on' => 'تاريخ النتيجة أو الإحالة لا يمكن أن يكون مستقبليًا.']);
        }
        if (! $old || $old->clinic_id != $row['clinic_id'] || $old->decided_by != $row['doctor_id']) {
            $this->context->check($f, $row['clinic_id'], $row['doctor_id'], $v->visit_date, 'outcome.doctor_id', false);
        }
        $result = DB::table('visit_results')->where('code', $row['code'])->where('is_active', true)->first();
        if (! $result) {
            throw ValidationException::withMessages(['outcome.code' => 'تعريف النتيجة غير متاح؛ راجع مسؤول الدليل.']);
        }
        $ref = $row['code'] === 'DOS-REFER';
        $fields = ['result_id' => $result->id, 'clinic_id' => $row['clinic_id'], 'decided_by' => $row['doctor_id'], 'outcome_on' => $row['outcome_on'], 'note' => $row['note'] ?? null, 'dossier_managed' => true, 'referral_target' => $ref ? $row['referral_target'] : null, 'outgoing_referral_date' => $ref ? $row['outgoing_referral_date'] : null, 'outgoing_referral_reason' => $ref ? $row['outgoing_referral_reason'] : null];
        $this->persist($r, $f, 'visit_outcomes', $old, $fields, ['visit_id' => $v->id, 'facility_id' => $f['id'], 'reporting_period_id' => null, 'client_request_id' => (string) Str::uuid()]);
    }
}
