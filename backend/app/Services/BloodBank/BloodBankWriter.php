<?php

namespace App\Services\BloodBank;

use App\Exceptions\BloodBankException;
use App\Http\Requests\BloodBank\SaveBloodProfile;
use App\Services\Clinics\ClinicAudit;
use App\Services\Clinics\ClinicCounts;
use App\Services\Directory\ClinicStaffLinks;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BloodBankWriter
{
    public function __construct(private BloodBankQueries $queries, private BloodBankAccess $access, private ClinicAudit $audit) {}

    public function once(Request $request, array $facility, array $data, string $operation, callable $write): int
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };
        $fingerprint = hash('sha256', $operation.json_encode($canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $facility, $data, $fingerprint, $write) {
            $key = ['facility_id' => $facility['id'], 'user_id' => $request->user()->id, 'request_id' => $data['request_id']];
            DB::table('blood_bank_requests')->insertOrIgnore($key + ['fingerprint' => $fingerprint, 'created_at' => now()]);
            $entry = DB::table('blood_bank_requests')->where($key)->lockForUpdate()->first();
            if ($entry->fingerprint !== $fingerprint) {
                throw new BloodBankException('BLOOD_BANK_REQUEST_CONFLICT', 'استُخدم معرّف الحفظ لمحتوى مختلف. راجع المسودة وحاول بطلب جديد.');
            }
            if ($entry->entity_id) {
                return (int) $entry->entity_id;
            }
            $id = $write();
            DB::table('blood_bank_requests')->where('id', $entry->id)->update(['entity_id' => $id]);

            return $id;
        }, 3);
    }

    public function profile(Request $request, array $facility, string $kind, array $input, ?int $id): int
    {
        try {
            return $this->once($request, $facility, $input, "$kind:".($id ?? 'new'), function () use ($request, $facility, $kind, $input, $id) {
                $prior = $id ? $this->queries->find($kind, $id, $facility['id']) : null;
                $links = app(ClinicStaffLinks::class);
                $links->lockStaff(array_filter([$input['responsible_staff_id'], $prior['responsible_staff_id'] ?? null]));
                $links->lockClinics(array_filter([$input['clinic_id'], $prior['clinic_id'] ?? null]));
                $old = $id ? $this->queries->find($kind, $id, $facility['id'], true) : null;
                $this->version($old, $input);
                $unchanged = $old && (int) $old['clinic_id'] === (int) $input['clinic_id'] && (int) $old['responsible_staff_id'] === (int) $input['responsible_staff_id'];
                if (! $unchanged && ! app(ClinicCounts::class)->currentDoctors($facility)->where('c.id', $input['clinic_id'])->where('s.id', $input['responsible_staff_id'])->exists()) {
                    throw ValidationException::withMessages(['responsible_staff_id' => 'اختر طبيبًا فعالًا مرتبطًا حاليًا بالعيادة في المنشأة المحددة.']);
                }
                if (! empty($input['blood_component_id']) && (! $old || $old['blood_component_id'] != $input['blood_component_id']) && ! DB::table('blood_components')->where('id', $input['blood_component_id'])->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['blood_component_id' => 'اختر مكوّنًا فعالًا من الدليل.']);
                }
                $person = array_fill_keys(SaveBloodProfile::PERSON, null);
                $manual = array_fill_keys(SaveBloodProfile::MANUAL_ADDRESS, null);
                $patient = $input['person_mode'] === 'patient' ? (int) $input['patient_id'] : null;
                if ($kind === 'donor' && ($patient && (! $old || $old['patient_id'] != $patient))) {
                    throw ValidationException::withMessages(['person_mode' => 'أدخل بيانات المتبرع مباشرة.']);
                }
                if ($patient && (! $old || (int) $old['patient_id'] !== $patient)) {
                    $this->access->patients($request->user(), $facility);
                    if (! DB::table('patients')->where('id', $patient)->where('status', 'active')->lockForUpdate()->exists()) {
                        throw ValidationException::withMessages(['patient_id' => 'المريض غير متاح للربط.']);
                    }
                }
                if (! $patient) {
                    $person = Arr::only($input, SaveBloodProfile::PERSON) + $person;
                    // Omission is not an instruction to erase a historical manual address.
                    foreach (SaveBloodProfile::MANUAL_ADDRESS as $key) {
                        $manual[$key] = array_key_exists($key, $input) ? $input[$key] : ($old[$key] ?? null);
                    }
                    if ($manual['governorate_text'] && ($person['governorate_id'] || $person['city_id'])) {
                        throw ValidationException::withMessages(['governorate_text' => 'اختر محافظة من الدليل أو أدخل محافظة خارج سوريا؛ لا تجمع المسارين.']);
                    }
                    if ($manual['city_text'] && ($person['city_id'] || (! $person['governorate_id'] && ! $manual['governorate_text']))) {
                        throw ValidationException::withMessages(['city_text' => 'المدينة اليدوية تحتاج محافظة، ولا تُجمع مع مدينة من الدليل.']);
                    }
                    if (! $person['birth_date'] && $person['birth_date_accuracy'] !== 'unknown') {
                        throw ValidationException::withMessages(['birth_date_accuracy' => 'اختر غير معروف عند غياب تاريخ الميلاد.']);
                    }
                    if ($person['governorate_id'] && ! DB::table('governorates')->where('id', $person['governorate_id'])->where(fn ($q) => $q->where('country_code', 'SY')->when($old && $old['governorate_id'] == $person['governorate_id'], fn ($q) => $q->orWhere('id', $old['governorate_id'])))->exists()) {
                        throw ValidationException::withMessages(['governorate_id' => 'المحافظة غير متاحة.']);
                    }
                    if ($person['city_id'] && ! DB::table('cities')->where('id', $person['city_id'])->where('governorate_id', $person['governorate_id'])->exists()) {
                        throw ValidationException::withMessages(['city_id' => 'اختر مدينة تابعة للمحافظة.']);
                    }
                }
                $fields = $person + $manual + ['patient_id' => $patient];
                $fields += Arr::only($input, ['clinic_id', 'responsible_staff_id']);
                foreach (['blood_group', 'rh', 'blood_component_id'] as $field) {
                    $fields[$field] = $input[$field] ?? null;
                }
                if ($kind === 'donor') {
                    if (! empty($input['beneficiary_entity'])) {
                        throw ValidationException::withMessages(['beneficiary_entity' => 'جهة المستفيد لا تخص المتبرع.']);
                    }
                    $fields['full_name'] = $patient ? $old['full_name'] : $person['first_name'].' '.$person['family_name'];
                    if ($patient) {
                        $fields['gender'] = $old['gender'];
                    }
                } else {
                    $fields['beneficiary_entity'] = $input['beneficiary_entity'] ?? null;
                }
                $fields['lock_version'] = ($old['lock_version'] ?? 0) + 1;
                $fields['updated_by'] = $request->user()->id;
                $fields['updated_at'] = now();
                $table = BloodBankQueries::table($kind);
                if ($id) {
                    DB::table($table)->where('id', $id)->update($fields);
                } else {
                    $id = DB::table($table)->insertGetId($fields + ['facility_id' => $facility['id'], BloodBankQueries::codeColumn($kind) => (string) Str::uuid(), 'entered_by' => $request->user()->id, 'created_at' => now()]);
                    DB::table($table)->where('id', $id)->update([BloodBankQueries::codeColumn($kind) => ($kind === 'donor' ? 'BD-' : 'BR-').str_pad((string) $id, 8, '0', STR_PAD_LEFT)]);
                }
                $oldScreens = DB::table('blood_bank_screenings')->where($kind.'_id', $id)->get()->keyBy('analyte');
                foreach ($input['screenings'] ?? [] as $screen) {
                    $previous = $oldScreens->get($screen['analyte']);
                    $result = array_key_exists('result', $screen) ? $screen['result'] : $previous?->result;
                    $test = array_key_exists('screening_test_id', $screen) ? $screen['screening_test_id'] : $previous?->screening_test_id;
                    if ($test && ($oldScreens->get($screen['analyte'])?->screening_test_id != $test) && ! DB::table('screening_tests')->where('id', $test)->where('blood_bank_analyte', $screen['analyte'])->where('is_active', true)->exists()) {
                        throw ValidationException::withMessages(['screenings' => 'طريقة الفحص غير معتمدة لهذا الفحص. يمكن إبقاؤها غير معروفة.']);
                    }
                    DB::table('blood_bank_screenings')->updateOrInsert([$kind.'_id' => $id, 'analyte' => $screen['analyte']], ['screening_test_id' => $test, 'status' => $screen['status'], 'result' => $result, 'updated_at' => now(), 'created_at' => $oldScreens->get($screen['analyte'])?->created_at ?? now()]);
                }
                $new = $this->queries->find($kind, $id, $facility['id']);
                $this->audit->record($request, $facility['id'], $id, $old ? 'updated' : 'created', $old ? $old + ['screenings' => $oldScreens->values()->all()] : null, $new + ['screenings' => DB::table('blood_bank_screenings')->where($kind.'_id', $id)->get()->all()], 'blood_'.$kind);

                return $id;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new BloodBankException('BLOOD_BANK_DUPLICATE', 'يوجد ملف لهذا المريض في المنشأة أو كود مستخدم؛ ابحث عن الملف الموجود.');
            }
            throw $e;
        }
    }

    public function donation(Request $request, array $facility, int $donor, array $input, ?int $id): int
    {
        return $this->once($request, $facility, $input, "donation:$donor:".($id ?? 'new'), function () use ($request, $facility, $donor, $input, $id) {
            $profile = $this->queries->find('donor', $donor, $facility['id'], true);
            $old = $id ? $this->queries->donation($donor, $id, $facility['id'], true) : null;
            $this->version($old, $input);
            if ((! $id && ! $profile['is_active']) || ($old && ($old['voided_at'] || $old['status'] !== 'pending'))) {
                throw new BloodBankException('BLOOD_BANK_STATE_CONFLICT', 'لا يمكن تسجيل أو تصحيح هذا التبرع في حالته الحالية.');
            }
            if ($input['donated_on'] > $facility['today']) {
                throw ValidationException::withMessages(['donated_on' => 'تاريخ التبرع الفعلي لا يمكن أن يكون في المستقبل.']);
            }
            $periods = DB::table('reporting_periods')->where('facility_id', $facility['id'])->where(fn ($q) => $q->where(fn ($dates) => $dates->where('starts_on', '<=', $input['donated_on'])->where('ends_on', '>=', $input['donated_on']))->orWhere('id', $old['reporting_period_id'] ?? 0))->orderBy('id')->lockForUpdate()->get();
            $matching = $periods->filter(fn ($p) => $p->starts_on <= $input['donated_on'] && $p->ends_on >= $input['donated_on']);
            if ($matching->count() !== 1 || $matching->first()->status !== 'open' || ($old && $periods->firstWhere('id', $old['reporting_period_id'])?->status !== 'open')) {
                throw ValidationException::withMessages(['donated_on' => 'يلزم وجود فترة تقارير مفتوحة واحدة تغطي التاريخ؛ الفترة السابقة أيضًا يجب أن تبقى مفتوحة عند التصحيح.']);
            }
            $fields = Arr::only($input, ['donated_on', 'blood_group', 'rh', 'units']) + ['reporting_period_id' => $matching->first()->id, 'updated_by' => $request->user()->id, 'updated_at' => now(), 'lock_version' => ($old['lock_version'] ?? 0) + 1];
            if ($id) {
                DB::table('blood_donations')->where('id', $id)->update($fields);
            } else {
                $id = DB::table('blood_donations')->insertGetId($fields + ['facility_id' => $facility['id'], 'donor_id' => $donor, 'entered_by' => $request->user()->id, 'status' => 'pending', 'created_at' => now()]);
            }
            $code = 'DON-'.str_replace('-', '', $input['donated_on']).'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            DB::table('blood_donation_codes')->insertOrIgnore(['blood_donation_id' => $id, 'code' => $code, 'created_at' => now()]);
            DB::table('blood_donations')->where('id', $id)->update(['donation_code' => $code]);
            $this->audit->record($request, $facility['id'], $id, $old ? 'corrected' : 'created', $old, $this->queries->donation($donor, $id, $facility['id']), 'blood_donation');

            return $id;
        });
    }

    private function version(?array $old, array $input): void
    {
        if ($old && (int) $old['lock_version'] !== (int) $input['lock_version']) {
            throw new BloodBankException('BLOOD_BANK_VERSION_CONFLICT', 'تغيّرت البيانات. مسودتك محفوظة؛ اجلب أحدث نسخة للمراجعة.');
        }
    }
}
