<?php

namespace App\Services\BloodBank;

use App\Exceptions\BloodBankException;
use App\Http\Requests\BloodBank\SaveBloodProfile;
use App\Services\Clinics\ClinicAudit;
use App\Services\Clinics\ClinicCounts;
use App\Services\Directory\ClinicStaffLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BloodEventWriter
{
    public function __construct(private BloodBankAccess $access, private BloodEventQueries $queries) {}

    public function person(Request $request, array $f, array $input, ?int $id = null): int
    {
        $this->access->facility($request->user(), $f['id'], $id ? 'update' : 'create');
        $old = $id ? DB::table('blood_bank_people')->where('facility_id', $f['id'])->where('id', $id)->lockForUpdate()->first() : null;
        if ($id && ! $old) {
            throw new BloodBankException('BLOOD_BANK_NOT_FOUND', 'الشخص غير موجود.', 404);
        }
        $this->version($old, $input);
        $rules = SaveBloodProfile::create('/', 'POST', $input)->rules();
        foreach (array_keys($rules) as $key) {
            if (in_array($key, ['facility_id', 'request_id', 'kind', 'lock_version', 'clinic_id', 'responsible_staff_id', 'blood_component_id', 'beneficiary_entity']) || str_starts_with($key, 'screenings')) {
                unset($rules[$key]);
            }
        }
        $data = Validator::make($input, $rules)->validate();
        $patient = $data['person_mode'] === 'patient' ? (int) $data['patient_id'] : null;
        if ($old && $patient !== $old->patient_id) {
            throw ValidationException::withMessages(['patient_id' => 'لا تغيّر هوية الشخص أثناء تعديل بياناته؛ الربط أو الدمج يحتاج مراجعة مستقلة.']);
        }
        if ($patient && ! $old) {
            $this->access->patients($request->user(), $f);
            if (! DB::table('patients')->where('id', $patient)->where('status', 'active')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['patient_id' => 'المريض غير متاح.']);
            }
            $existing = DB::table('blood_bank_people')->where('facility_id', $f['id'])->where('patient_id', $patient)->first();
            if ($existing) {
                return $existing->id;
            }
        }
        $keys = [...SaveBloodProfile::PERSON, ...SaveBloodProfile::MANUAL_ADDRESS];
        $fields = array_fill_keys($keys, null);
        if (! $patient) {
            $fields = Arr::only($data, $keys) + $fields;
            if (! $fields['birth_date'] && $fields['birth_date_accuracy'] !== 'unknown') {
                throw ValidationException::withMessages(['birth_date_accuracy' => 'دقة الميلاد غير معروفة عند غياب التاريخ.']);
            }
            if ($fields['governorate_text'] && ($fields['governorate_id'] || $fields['city_id'])) {
                throw ValidationException::withMessages(['governorate_text' => 'لا تجمع عنوانًا يدويًا مع روابط محافظة أو مدينة.']);
            }
            if ($fields['city_text'] && ($fields['city_id'] || (! $fields['governorate_id'] && ! $fields['governorate_text']))) {
                throw ValidationException::withMessages(['city_text' => 'المدينة اليدوية تحتاج محافظة دون ربط مدينة أخرى.']);
            }
            if ($fields['governorate_id'] && ! DB::table('governorates')->where('id', $fields['governorate_id'])->where('country_code', 'SY')->exists()) {
                throw ValidationException::withMessages(['governorate_id' => 'اختر محافظة سورية معتمدة.']);
            }
            if ($fields['city_id'] && ! DB::table('cities')->where('id', $fields['city_id'])->where('governorate_id', $fields['governorate_id'])->exists()) {
                throw ValidationException::withMessages(['city_id' => 'المدينة لا تتبع المحافظة.']);
            }
        }
        $fields += ['patient_id' => $patient, 'blood_group' => $data['blood_group'] ?? null, 'rh' => $data['rh'] ?? null, 'updated_by' => $request->user()->id, 'updated_at' => now(), 'lock_version' => ($old->lock_version ?? 0) + 1];
        if ($old) {
            DB::table('blood_bank_people')->where('id', $id)->update($fields);
        } else {
            $id = DB::table('blood_bank_people')->insertGetId($fields + ['facility_id' => $f['id'], 'code' => (string) Str::uuid(), 'entered_by' => $request->user()->id, 'created_at' => now()]);
            DB::table('blood_bank_people')->where('id', $id)->update(['code' => 'BP-'.str_pad((string) $id, 8, '0', STR_PAD_LEFT)]);
        }
        app(ClinicAudit::class)->record($request, $f['id'], $id, $old ? 'updated' : 'created', $old ? (array) $old : null, (array) DB::table('blood_bank_people')->find($id), 'blood_bank_person');

        return $id;
    }

    public function save(Request $request, array $f, array $data, ?int $id = null): int
    {
        $this->access->facility($request->user(), $f['id'], ($data['kind'] === 'donation' ? 'donations.' : 'benefits.').($id ? 'update' : 'create'));

        return app(BloodBankWriter::class)->once($request, $f, $data, 'unified-event:'.($id ?? 'new'), function () use ($request, $f, $data, $id) {
            DB::table('facilities')->where('id', $f['id'])->lockForUpdate()->first();
            $old = $id ? DB::table('blood_bank_events')->where('id', $id)->where('facility_id', $f['id'])->lockForUpdate()->first() : null;
            if ($id && ! $old) {
                throw new BloodBankException('BLOOD_BANK_NOT_FOUND', 'الواقعة غير موجودة.', 404);
            }
            $this->version($old, $data);
            $before = $old ? $this->queries->event($id, $f['id']) : null;
            if ($old && ($old->voided_at || ($old->kind === 'donation' && $old->status !== 'pending'))) {
                throw new BloodBankException('BLOOD_BANK_STATE_CONFLICT', 'لا يمكن تعديل الواقعة في حالتها الحالية.');
            }
            if ($old && ($old->kind !== $data['kind'] || $old->benefit_kind !== ($data['benefit_kind'] ?? null) || (int) $old->person_id !== (int) ($data['person_id'] ?? 0) || (int) $old->issue_event_id !== (int) ($data['issue_event_id'] ?? 0))) {
                throw ValidationException::withMessages(['person_id' => 'نوع الواقعة وهوية الشخص وارتباط مراحل العملية ثابتة بعد التسجيل.']);
            }
            if (($old && $old->quantity_unit !== $data['quantity_unit']) || (! $old && $data['quantity_unit'] !== 'kg')) {
                throw ValidationException::withMessages(['quantity_unit' => 'الجديد بالكيلوغرام؛ لا تحويل للوحدات التاريخية دون بيانات تحويل.']);
            }
            $personId = $data['person_id'] ?? $this->person($request, $f, $data['person']);
            $person = DB::table('blood_bank_people')->where('id', $personId)->where('facility_id', $f['id'])->lockForUpdate()->first();
            if (! $person || ! $person->is_active) {
                throw new BloodBankException('BLOOD_BANK_NOT_FOUND', 'الشخص غير متاح في المنشأة.', 404);
            }
            if ($data['occurred_on'] > $f['today']) {
                throw ValidationException::withMessages(['occurred_on' => 'التاريخ الفعلي لا يمكن أن يكون في المستقبل.']);
            }
            $period = DB::table('reporting_periods')->where('facility_id', $f['id'])->where('starts_on', '<=', $data['occurred_on'])->where('ends_on', '>=', $data['occurred_on'])->lockForUpdate()->get();
            if ($period->count() !== 1 || $period[0]->status !== 'open' || ($old && DB::table('reporting_periods')->where('id', $old->reporting_period_id)->value('status') !== 'open')) {
                throw ValidationException::withMessages(['occurred_on' => 'يلزم وجود فترة تقارير مفتوحة واحدة تغطي التاريخ؛ والفترة السابقة مفتوحة عند التعديل.']);
            }
            $links = app(ClinicStaffLinks::class);
            $links->lockStaff(array_filter([$data['responsible_staff_id'], $old->responsible_staff_id ?? null]));
            $links->lockClinics(array_filter([$data['clinic_id'], $old->clinic_id ?? null]));
            $same = $old && (int) $old->clinic_id === (int) $data['clinic_id'] && (int) $old->responsible_staff_id === (int) $data['responsible_staff_id'];
            if (! $same && ! app(ClinicCounts::class)->currentDoctors($f)->where('c.id', $data['clinic_id'])->where('s.id', $data['responsible_staff_id'])->exists()) {
                throw ValidationException::withMessages(['responsible_staff_id' => 'اختر طبيبًا مؤهلًا مرتبطًا حاليًا بالعيادة في المنشأة.']);
            }
            if ((! $old || $old->blood_component_id != $data['blood_component_id']) && ! DB::table('blood_components')->where('id', $data['blood_component_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['blood_component_id' => 'اختر مكوّنًا فعالًا.']);
            }
            $issueId = $data['issue_event_id'] ?? null;
            if ($issueId) {
                $issue = DB::table('blood_bank_events')->where('id', $issueId)->where('facility_id', $f['id'])->where('person_id', $personId)->where('benefit_kind', 'issue')->whereNull('voided_at')->lockForUpdate()->first();
                if (! $issue || $issue->blood_component_id != $data['blood_component_id'] || $issue->occurred_on > $data['occurred_on'] || DB::table('blood_bank_events')->where('issue_event_id', $issueId)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                    throw ValidationException::withMessages(['issue_event_id' => 'اختر صرفًا سابقًا لنفس الشخص والمكوّن، لم يُربط بنقل آخر.']);
                }
            }
            if ($old && $old->benefit_kind === 'issue') {
                $child = DB::table('blood_bank_events')->where('issue_event_id', $id)->first();
                if ($child && ($child->blood_component_id != $data['blood_component_id'] || $data['occurred_on'] > $child->occurred_on)) {
                    throw ValidationException::withMessages(['occurred_on' => 'التعديل يتعارض مع النقل المرتبط بهذا الصرف.']);
                }
            }
            $fields = Arr::only($data, ['kind', 'benefit_kind', 'occurred_on', 'blood_component_id', 'clinic_id', 'responsible_staff_id', 'quantity', 'quantity_unit', 'beneficiary_entity', 'entity_address']);
            foreach (['blood_group', 'rh'] as $key) {
                $fields[$key] = array_key_exists($key, $data) ? $data[$key] : ($old->$key ?? $person->$key);
            }
            if ($data['kind'] === 'donation' && (! $fields['blood_group'] || ! $fields['rh'])) {
                throw ValidationException::withMessages(['blood_group' => 'التبرع الفعلي يحتاج ABO وRh وفق سجل التبرعات الحالي.']);
            }
            $fields += ['person_id' => $personId, 'facility_id' => $f['id'], 'issue_event_id' => $issueId, 'reporting_period_id' => $period[0]->id, 'updated_by' => $request->user()->id, 'updated_at' => now(), 'lock_version' => ($old->lock_version ?? 0) + 1];
            $clinical = ['facility_id' => $f['id'], 'reporting_period_id' => $period[0]->id, 'blood_group' => $fields['blood_group'], 'rh' => $fields['rh'], 'units' => $data['quantity'], 'quantity_unit' => $data['quantity_unit'], 'updated_by' => $request->user()->id, 'updated_at' => now(), 'lock_version' => $fields['lock_version']];
            $sourceId = null;
            if ($data['kind'] === 'donation') {
                $anchor = $old ? DB::table('blood_donors')->find(DB::table('blood_donations')->where('id', $old->blood_donation_id)->value('donor_id')) : DB::table('blood_donors')->where('person_id', $personId)->orderBy('id')->first();
                if (! $anchor) {
                    $anchorId = DB::table('blood_donors')->insertGetId(['facility_id' => $f['id'], 'person_id' => $personId, 'patient_id' => $person->patient_id, 'donor_code' => $person->code, 'full_name' => '', 'entered_by' => $request->user()->id, 'created_at' => now()]);
                } else {
                    $anchorId = $anchor->id;
                }
                $clinical += ['donor_id' => $anchorId, 'donated_on' => $data['occurred_on']];
                $sourceId = $old->blood_donation_id ?? DB::table('blood_donations')->insertGetId($clinical + ['entered_by' => $request->user()->id, 'created_at' => now()]);
                if ($old) {
                    DB::table('blood_donations')->where('id', $sourceId)->update($clinical);
                }
                $fields['blood_donation_id'] = $sourceId;
                $fields['status'] = 'pending';
            } elseif ($data['benefit_kind'] === 'transfusion') {
                $clinical += ['patient_id' => $person->patient_id, 'external_recipient_name' => $person->patient_id ? null : $this->queries->person($personId, $f['id'])['name'], 'transfused_on' => $data['occurred_on'], 'blood_component_id' => $data['blood_component_id'], 'beneficiary_entity' => $data['beneficiary_entity'] ?? null];
                if ($old) {
                    // A correction to the event must not rewrite the historical identity
                    // snapshot from a subsequently edited current person profile.
                    unset($clinical['external_recipient_name']);
                }
                $sourceId = $old->blood_transfusion_id ?? DB::table('blood_transfusions')->insertGetId($clinical + ['entered_by' => $request->user()->id, 'created_at' => now()]);
                if ($old) {
                    DB::table('blood_transfusions')->where('id', $sourceId)->update($clinical);
                }
                $fields['blood_transfusion_id'] = $sourceId;
            }
            if ($old) {
                DB::table('blood_bank_events')->where('id', $id)->update($fields);
            } else {
                $id = DB::table('blood_bank_events')->insertGetId($fields + ['code' => (string) Str::uuid(), 'entered_by' => $request->user()->id, 'created_at' => now()]);
            }
            $code = ($data['kind'] === 'donation' ? 'DON-' : ($data['benefit_kind'] === 'issue' ? 'ISS-' : 'TRF-')).str_replace('-', '', $data['occurred_on']).'-'.str_pad((string) ($sourceId ?? $id), 6, '0', STR_PAD_LEFT);
            DB::table('blood_bank_event_codes')->insertOrIgnore(['event_id' => $id, 'code' => $code, 'created_at' => now()]);
            if (DB::table('blood_bank_event_codes')->where('code', $code)->value('event_id') !== $id) {
                throw new BloodBankException('BLOOD_BANK_CODE_CONFLICT', 'الكود مرتبط بواقعة أخرى.');
            }
            DB::table('blood_bank_events')->where('id', $id)->update(['code' => $code]);
            if ($data['kind'] === 'donation') {
                DB::table('blood_donations')->where('id', $sourceId)->update(['donation_code' => $code]);
                DB::table('blood_donation_codes')->insertOrIgnore(['blood_donation_id' => $sourceId, 'code' => $code, 'created_at' => now()]);
            }
            foreach ($data['screenings'] ?? [] as $screen) {
                $previous = DB::table('blood_bank_event_screenings')->where('event_id', $id)->where('analyte', $screen['analyte'])->first();
                DB::table('blood_bank_event_screenings')->updateOrInsert(['event_id' => $id, 'analyte' => $screen['analyte']], ['status' => $screen['status'], 'created_at' => $previous->created_at ?? now(), 'updated_at' => now()]);
            }
            app(ClinicAudit::class)->record($request, $f['id'], $id, $old ? 'corrected' : 'created', $before, $this->queries->event($id, $f['id']), 'blood_bank_event');

            return $id;
        });
    }

    private function version(?object $old, array $input): void
    {
        if ($old && $old->lock_version !== (int) ($input['lock_version'] ?? 0)) {
            throw new BloodBankException('BLOOD_BANK_VERSION_CONFLICT', 'تغيّرت البيانات. مسودتك محفوظة؛ اجلب أحدث نسخة للمراجعة.');
        }
    }
}
