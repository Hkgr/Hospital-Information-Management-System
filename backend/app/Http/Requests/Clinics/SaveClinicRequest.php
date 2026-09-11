<?php

namespace App\Http\Requests\Clinics;

use Illuminate\Foundation\Http\FormRequest;

class SaveClinicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facility_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:40'],
            'name_ar' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'specialty_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
            'lock_version' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'doctor_add_ids' => ['sometimes', 'array', 'max:200'],
            'doctor_add_ids.*' => ['integer', 'min:1', 'distinct'],
            'doctor_remove_ids' => [$this->isMethod('POST') ? 'prohibited' : 'sometimes', 'array', 'max:200'],
            'doctor_remove_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
