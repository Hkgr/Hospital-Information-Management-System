<?php

namespace App\Services\Dossiers;

use App\Http\Requests\Dossiers\SaveDossierSection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DossierPersonalWriter
{
    public function __construct(private DossierWrites $writes, private DossierAccess $access) {}

    public function save(Request $r, array $f, array $input, ?int $id, bool $withoutVisit = false): int
    {
        $this->access->global($r->user(), $id ? 'patients.update' : ($input['person_mode'] === 'new' ? 'patients.create' : 'patients.search'));
        if (! $id && ! $withoutVisit) {
            $this->access->facility($r->user(), $f['id'], 'visits.create');
        }
        try {
            return $this->writes->once($r, $f, $input, 'personal:'.($id ?? 'new'), function () use ($r, $f, $input, $id, $withoutVisit) {
                $old = $id ? $this->writes->dossier($f, $id) : null;
                if ($old) {
                    DossierWrites::version($old, $input['lock_version']);
                }
                if (! $old) {
                    if (! $withoutVisit && $input['visit_date'] > $f['today']) {
                        throw ValidationException::withMessages(['visit_date' => 'لا يمكن تسجيل زيارة فعلية مستقبلية.']);
                    }
                    // Serialize canonical-code/legacy-code reservations across facilities.
                    app(PatientCardCodes::class)->reserve();
                }
                $patientId = $old['patient_id'] ?? ($input['patient_id'] ?? null);
                $patient = $patientId ? DB::table('patients')->where('id', $patientId)->where('status', 'active')->lockForUpdate()->first() : null;
                if ($patientId) {
                    abort_unless($patient, 404);
                }
                if ($old && $input['code'] !== $patient->patient_code) {
                    throw ValidationException::withMessages(['code' => 'كود المريض ثابت؛ لا يُغيَّر من تعديل بيانات البطاقة.']);
                }
                if (! $patient && (DB::table('patients')->where('patient_code', $input['code'])->exists()
                    || DB::table('patient_dossiers')->where('code', $input['code'])->exists())) {
                    throw ValidationException::withMessages(['code' => 'الكود مستخدم حاليًا أو محفوظ كمعرّف تاريخي. اختر المريض الموجود صراحة أو راجع المسؤول.']);
                }
                if (! $id && $patient) {
                    $existing = DB::table('patient_dossiers')->where('patient_id', $patientId)->where('facility_id', $f['id'])->value('id');
                    if ($existing) {
                        throw new HttpResponseException(response()->json(['error' => ['code' => 'DOSSIER_ALREADY_EXISTS', 'message' => 'للمريض ملف طبي محفوظ في هذا المشفى. افتح بطاقة المريض لإضافة زيارة أو استكمال المسودة؛ لم تُحفظ المسودة الجديدة.', 'existing_dossier_id' => (int) $existing]], 409));
                    }
                }
                if ($id || ! $patient) {
                    if ($id) {
                        DossierWrites::version((array) $patient, $input['patient_lock_version']);
                    }
                    $fields = Arr::only($input, SaveDossierSection::PERSON) + array_fill_keys(SaveDossierSection::PERSON, null);
                    foreach (['marital_status', 'smoking_status', 'alcohol_status'] as $key) {
                        $fields[$key] = $fields[$key] ?: 'unknown';
                    }
                    if (($fields['displacement_status'] ?? '') !== 'idp') {
                        $fields['permanent_address'] = null;
                    }
                    if (($fields['birth_date'] && $fields['birth_date'] > $f['today']) || (! $fields['birth_date'] && $fields['birth_date_accuracy'] !== 'unknown')) {
                        throw ValidationException::withMessages(['birth_date' => 'أدخل ميلادًا غير مستقبلي، أو اختر غير معروف مع تاريخ فارغ.']);
                    }
                    if ($fields['governorate_id'] && ! DB::table('governorates')->where('id', $fields['governorate_id'])->where('country_code', 'SY')->exists()) {
                        throw ValidationException::withMessages(['governorate_id' => 'اختر محافظة من الدليل السوري، أو اكتب العنوان الخارجي في عنوان السكن.']);
                    }
                    if ($fields['city_id'] && (! $fields['governorate_id'] || ! DB::table('cities')->where('id', $fields['city_id'])->where('governorate_id', $fields['governorate_id'])->exists())) {
                        throw ValidationException::withMessages(['city_id' => 'اختر مدينة تابعة للمحافظة.']);
                    }
                    $fields += ['search_name' => trim(preg_replace('/\s+/u', ' ', $fields['first_name'].' '.$fields['family_name'])), 'lock_version' => ($patient?->lock_version ?? 0) + 1, 'updated_at' => now()];
                    if ($patient) {
                        DB::table('patients')->where('id', $patientId)->update($fields);
                    } else {
                        $patientId = DB::table('patients')->insertGetId($fields + ['patient_code' => $input['code'], 'identity_document_type' => 'unknown', 'identity_check_status' => 'pending', 'created_by' => $r->user()->id, 'created_at' => now()]);
                    }
                    $this->writes->audit($r, $f, 'patient', $patientId, $patient ? Arr::only((array) $patient, [...SaveDossierSection::PERSON, 'lock_version']) : null, $fields);
                }
                // patients is the one global card. patient_dossiers rows remain local
                // clinical contexts; their historical codes are immutable aliases.
                $values = Arr::only($input, ['opening_date']) + ['updated_by' => $r->user()->id, 'updated_at' => now(), 'lock_version' => ($old['lock_version'] ?? 0) + 1];
                if ($id) {
                    DB::table('patient_dossiers')->where('id', $id)->update($values);
                } else {
                    $id = DB::table('patient_dossiers')->insertGetId($values + ['code' => null, 'facility_id' => $f['id'], 'patient_id' => $patientId, 'status' => 'draft', 'entered_by' => $r->user()->id, 'created_at' => now()]);
                    if (! $withoutVisit) {
                        $visit = ['facility_id' => $f['id'], 'patient_id' => $patientId, 'dossier_id' => $id,
                            'visit_date' => $input['visit_date'],
                            'dossier_visit_kind' => 'initial', 'reporting_period_id' => null,
                            'visit_no' => 'V-'.Str::uuid(), 'client_request_id' => $input['request_id'],
                            'entered_by' => $r->user()->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()];
                        $visitId = DB::table('visits')->insertGetId($visit);
                        DB::table('patient_dossiers')->where('id', $id)->update(['registration_visit_id' => $visitId]);
                        $this->writes->progress($r, $f, $id, 'visit', 'in_progress', $visitId);
                        $this->writes->audit($r, $f, 'dossier_visit', $visitId, null, $visit);
                    }
                }
                $this->writes->progress($r, $f, $id, 'personal');
                $this->writes->audit($r, $f, 'patient_dossier', $id, $old, $this->writes->dossier($f, $id));

                return $id;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'الكود مستخدم في المنشأة. اختر كودًا مختلفًا دون تغيير هوية المريض.']);
            }
            throw $e;
        }
    }
}
