<?php

namespace App\Http\Requests\Clinics;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClinicQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facility_id' => ['required', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'doctor_id' => ['nullable', 'integer', 'min:1'],
            'clinic_id' => ['nullable', 'integer', 'min:1'],
            'specialty_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(['code', 'name_ar', 'doctor_count', 'patient_count', 'is_active'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'columns' => ['sometimes', 'array', 'min:1', 'max:7'],
            'columns.*' => ['string', 'distinct', Rule::in(['number', 'code', 'name_ar', 'description', 'doctors', 'doctor_count', 'patient_count'])],
        ];
    }
}
