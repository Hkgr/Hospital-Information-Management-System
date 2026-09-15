<?php

namespace App\Http\Requests\Dossiers;

use App\Http\Requests\BloodBank\SaveBloodProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDossierSection extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['facility_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'lock_version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1']];
        $section = $this->route('section');
        if ($section === 'personal') {
            $newPatient = $this->isMethod('PUT') || $this->input('person_mode') === 'new';
            $rules += ['code' => [$this->input('person_mode') === 'existing' ? 'prohibited' : 'required', 'string', 'max:40'], 'opening_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
                'person_mode' => [$this->isMethod('POST') ? 'required' : 'prohibited', Rule::in(['existing', 'new'])],
                'patient_id' => [$this->input('person_mode') === 'existing' ? 'required' : 'prohibited', 'integer', 'min:1'],
                'patient_lock_version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1']];
            $rules['visit_date'] = [$this->isMethod('POST') ? 'required' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'];
            $rules['visit_type_id'] = [$this->isMethod('POST') ? 'required' : 'prohibited', 'integer', 'min:1'];
            foreach (SaveBloodProfile::PERSON as $key) {
                $rules[$key] = $newPatient ? ['nullable'] : ['prohibited'];
            }
            if ($newPatient) {
                foreach (['first_name' => 80, 'family_name' => 80, 'father_name' => 80, 'mother_name' => 120, 'phone' => 30, 'alt_phone' => 30, 'address_line' => 255] as $key => $max) {
                    $rules[$key] = [in_array($key, ['first_name', 'family_name']) ? 'required' : 'nullable', 'string', 'max:'.$max];
                }
                $rules['birth_date'] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'];
                $rules['birth_date_accuracy'] = ['required', Rule::in(['exact', 'year_only', 'estimated', 'unknown'])];
                $rules['gender'] = ['required', Rule::in(['male', 'female', 'unknown'])];
                $rules['displacement_status'] = ['required', Rule::in(['resident', 'idp', 'unknown'])];
                foreach (['governorate_id', 'city_id'] as $key) {
                    $rules[$key] = ['nullable', 'integer', 'min:1'];
                }
            }
        } elseif ($section === 'medical') {
            $rules += ['disability_text' => ['nullable', 'string', 'max:10000'], 'clinical_history' => ['nullable', 'string', 'max:20000'], 'is_oncology' => ['required', 'boolean'], 'confirm_hide_oncology' => ['sometimes', 'boolean']];
            foreach (['history' => ['medical', 'surgical', 'medication', 'family'], 'treatment' => ['surgical', 'chemotherapy', 'radiotherapy', 'other']] as $group => $codes) {
                $rules[$group] = ['sometimes', 'array', 'max:4'];
                $rules[$group.'.*'] = ['string', 'distinct', Rule::in($codes)];
            }
            $rules += ['previous_examinations' => ['nullable', 'string', 'max:20000'], 'medication_source' => ['nullable', Rule::in(['ministry_of_health', 'al_rowad', 'other_organization', 'personal_expense', 'none'])],
                'other_organization' => [Rule::requiredIf($this->boolean('is_oncology') && $this->input('medication_source') === 'other_organization'), Rule::prohibitedIf($this->input('medication_source') !== 'other_organization'), 'nullable', 'string', 'max:200']];
        } else {
            $rules += ['visit_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'], 'visit_type_id' => ['required', 'integer', 'min:1'], 'is_referred' => ['required', 'boolean'],
                'referring_hospital' => [$this->boolean('is_referred') ? 'required' : 'prohibited', 'nullable', 'string', 'max:200'],
                'referral_date' => [$this->boolean('is_referred') ? 'required' : 'prohibited', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:visit_date'],
                'referral_reason' => [$this->boolean('is_referred') ? 'required' : 'prohibited', 'nullable', 'string', 'max:10000'],
                'diagnoses' => ['required', 'array', 'max:100'], 'diagnoses.*' => ['array:id,lock_version,diagnosis_id,diagnosed_on,clinic_id,diagnosing_staff_id,remove,void_reason'],
                'diagnoses.*.id' => ['nullable', 'integer', 'min:1', 'distinct'], 'diagnoses.*.lock_version' => ['required_with:diagnoses.*.id', 'integer', 'min:1'],
                'diagnoses.*.diagnosis_id' => ['required', 'integer', 'min:1'], 'diagnoses.*.diagnosed_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
                'diagnoses.*.clinic_id' => ['required', 'integer', 'min:1'], 'diagnoses.*.diagnosing_staff_id' => ['required', 'integer', 'min:1'],
                'diagnoses.*.remove' => ['sometimes', 'boolean'], 'diagnoses.*.void_reason' => ['nullable', 'string', 'max:255']];
            // An empty diagnoses array is a valid partial visit.
            $rules['diagnoses'] = ['present', 'array', 'max:100'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'present' => 'أرسل بيانات القسم ولو كانت فارغة.', 'prohibited' => 'لا ترسل هذا الحقل في المسار المختار.', 'max' => 'القيمة تتجاوز الحد المسموح.', 'in' => 'اختر قيمة معتمدة.', 'date_format' => 'أدخل تاريخًا كاملًا صحيحًا.', 'before_or_equal' => 'التاريخ يتجاوز التاريخ المسموح.', 'distinct' => 'لا تكرر القيمة نفسها.', 'uuid' => 'معرّف الحفظ غير صالح.'];
    }
}
