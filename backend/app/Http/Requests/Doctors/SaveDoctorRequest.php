<?php

namespace App\Http\Requests\Doctors;

use Illuminate\Foundation\Http\FormRequest;

class SaveDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $linksOnly = $this->routeIs('doctors.updateClinics');
        $fields = [
            'code' => ['required', 'string', 'max:40'],
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
            'lock_version' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'clinic_add_ids' => ['sometimes', 'array', 'max:200'],
            'clinic_add_ids.*' => ['integer', 'min:1', 'distinct'],
            'clinic_remove_ids' => [$this->isMethod('POST') ? 'prohibited' : 'sometimes', 'array', 'max:200'],
            'clinic_remove_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
