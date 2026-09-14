<?php

namespace App\Http\Requests\BloodBank;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBloodProfile extends FormRequest
{
    public const PERSON = ['first_name', 'family_name', 'father_name', 'mother_name', 'birth_date', 'birth_date_accuracy', 'gender', 'phone', 'alt_phone', 'governorate_id', 'city_id', 'address_line', 'displacement_status'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $linked = $this->input('person_mode') === 'patient';
        $rules = ['facility_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'],
            'kind' => [$this->isMethod('POST') ? 'required' : 'prohibited', Rule::in(['donor', 'recipient'])],
            'lock_version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'person_mode' => ['required', Rule::in(['direct', 'patient'])],
            'patient_id' => [$linked ? 'required' : 'prohibited', 'integer', 'min:1'],
            'clinic_id' => ['required', 'integer', 'min:1'], 'responsible_staff_id' => ['required', 'integer', 'min:1'],
            'blood_component_id' => ['nullable', 'integer', 'min:1'], 'beneficiary_entity' => ['nullable', 'string', 'max:200'],
            'blood_group' => ['nullable', Rule::in(['A', 'B', 'AB', 'O'])], 'rh' => ['nullable', Rule::in(['positive', 'negative'])],
            'screenings' => ['required', 'array', 'size:3'], 'screenings.*' => ['array:analyte,screening_test_id,status,result'],
            'screenings.*.analyte' => ['required', 'distinct', Rule::in(['HBsAg', 'HCV', 'HIV'])],
            'screenings.*.screening_test_id' => ['nullable', 'integer', 'min:1'],
            'screenings.*.status' => ['required', Rule::in(['not_requested', 'requested', 'pending', 'complete', 'cancelled'])],
            'screenings.*.result' => ['nullable', Rule::in(['negative', 'positive', 'indeterminate'])]];
        foreach (self::PERSON as $field) {
            $rules[$field] = $linked ? ['prohibited'] : ['nullable'];
        }
        if (! $linked) {
            foreach (['first_name' => 80, 'family_name' => 80, 'father_name' => 80, 'mother_name' => 120, 'phone' => 30, 'alt_phone' => 30, 'address_line' => 255] as $key => $max) {
                $rules[$key] = [in_array($key, ['first_name', 'family_name']) ? 'required' : 'nullable', 'string', 'max:'.$max];
            }
            $rules['birth_date'] = ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'];
            $rules['birth_date_accuracy'] = ['required', Rule::in(['exact', 'year_only', 'estimated', 'unknown'])];
            $rules['gender'] = ['required', Rule::in(['male', 'female', 'unknown'])];
            $rules['displacement_status'] = ['required', Rule::in(['resident', 'idp', 'unknown'])];
            foreach (['governorate_id', 'city_id'] as $key) {
                $rules[$key] = ['nullable', 'integer', 'min:1'];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'prohibited' => 'لا ترسل هذا الحقل في المسار المختار.', 'max' => 'القيمة أطول من الحد المسموح.', 'in' => 'اختر قيمة معتمدة.', 'uuid' => 'معرّف طلب الحفظ غير صالح.'];
    }
}
