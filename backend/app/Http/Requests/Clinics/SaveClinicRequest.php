<?php

namespace App\Http\Requests\Clinics;

use Illuminate\Foundation\Http\FormRequest;

class SaveClinicRequest extends FormRequest
{
    public function messages(): array
    {
        return ['code.prohibited' => 'يصدر النظام الكود تلقائيًا ولا يمكن تغييره.', 'request_id.required' => 'معرّف طلب الحفظ مطلوب.', 'request_id.uuid' => 'معرّف طلب الحفظ غير صالح.'];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facility_id' => ['required', 'integer', 'min:1'],
            'request_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'],
            'code' => ['prohibited'],
            'name_ar' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'specialty_id' => ['nullable', 'integer', 'min:1'],
            'care_setting' => ['nullable', 'in:outpatient,inpatient,surgical,radiology'],
            'clinic_kind' => ['nullable', 'in:blood,oncology,thalassemia,surgical'],
            'inpatient_kind' => ['prohibited'],
            'is_active' => ['required', 'boolean'],
            'lock_version' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'doctor_add_ids' => ['sometimes', 'array', 'max:200'],
            'assignment_starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'doctor_add_ids.*' => ['integer', 'min:1', 'distinct'],
            'doctor_remove_ids' => [$this->isMethod('POST') ? 'prohibited' : 'sometimes', 'array', 'max:200'],
            'doctor_remove_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
