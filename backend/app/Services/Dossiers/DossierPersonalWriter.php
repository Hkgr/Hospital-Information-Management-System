<?php

namespace App\Services\Dossiers;

use App\Http\Requests\BloodBank\SaveBloodProfile;
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

    public function save(Request $r, array $f, array $input, ?int $id): int
    {
        $this->access->global($r->user(), $id ? 'patients.update' : ($input['person_mode'] === 'new' ? 'patients.create' : 'patients.search'));
        try {
            return $this->writes->once($r, $f, $input, 'personal:'.($id ?? 'new'), function () use ($r, $f, $input, $id) {
                $old = $id ? $this->writes->dossier($f, $id) : null;
                if ($old) {
                    DossierWrites::version($old, $input['lock_version']);
                }
                $patientId = $old['patient_id'] ?? ($input['patient_id'] ?? null);
                $patient = $patientId ? DB::table('patients')->where('id', $patientId)->where('status', 'active')->lockForUpdate()->first() : null;
                if ($patientId) {
                    abort_unless($patient, 404);
                }
                if (! $id && $patient) {
                    $existing = DB::table('patient_dossiers')->where('patient_id', $patientId)->where('facility_id', $f['id'])->value('id');
                    if ($existing) {
                        throw new HttpResponseException(response()->json(['error' => ['code' => 'DOSSIER_ALREADY_EXISTS', 'message' => 'للمريض إضبارة في هذا المشفى. افتح الإضبارة الموجودة أو اختر مريضًا آخر؛ لم تُحفظ بيانات المسودة الجديدة.', 'existing_dossier_id' => (int) $existing]], 409));
                    }
                }
                if ($id || ! $patient) {
                    if ($id) {
                        DossierWrites::version((array) $patient, $input['patient_lock_version']);
                    }
                    $fields = Arr::only($input, SaveBloodProfile::PERSON) + array_fill_keys(SaveBloodProfile::PERSON, null);
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
                        $patientId = DB::table('patients')->insertGetId($fields + ['patient_code' => 'P-'.Str::uuid(), 'identity_document_type' => 'unknown', 'identity_check_status' => 'pending', 'created_by' => $r->user()->id, 'created_at' => now()]);
                    }
                    $this->writes->audit($r, $f, 'patient', $patientId, $patient ? Arr::only((array) $patient, [...SaveBloodProfile::PERSON, 'lock_version']) : null, $fields);
                }
                $values = Arr::only($input, ['code', 'opening_date']) + ['updated_by' => $r->user()->id, 'updated_at' => now(), 'lock_version' => ($old['lock_version'] ?? 0) + 1];
                if ($id) {
                    DB::table('patient_dossiers')->where('id', $id)->update($values);
                } else {
                    $id = DB::table('patient_dossiers')->insertGetId($values + ['facility_id' => $f['id'], 'patient_id' => $patientId, 'status' => 'draft', 'entered_by' => $r->user()->id, 'created_at' => now()]);
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
