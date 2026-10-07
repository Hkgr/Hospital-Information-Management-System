<?php

namespace App\Http\Requests\Doctors;

use Illuminate\Foundation\Http\FormRequest;

class SaveDoctorRequest extends FormRequest
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
        $linksOnly = $this->routeIs('doctors.updateClinics');
        $fields = [
            'code' => ['prohibited'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'staff_type_id' => ['required', 'integer', 'min:1'],
            'license_no' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['required', 'boolean'],
            'specialty_ids' => ['present', 'array', 'max:100'],
            'specialty_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
        if ($linksOnly) {
            $fields = array_fill_keys(array_keys($fields), ['prohibited']);
        }

        return $fields + [
            'facility_id' => ['required', 'integer', 'min:1'],
            'request_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'],
            'lock_version' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'clinic_add_ids' => ['sometimes', 'array', 'max:200'],
            'assignment_starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'clinic_add_ids.*' => ['integer', 'min:1', 'distinct'],
            'clinic_remove_ids' => [$this->isMethod('POST') ? 'prohibited' : 'sometimes', 'array', 'max:200'],
            'clinic_remove_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
