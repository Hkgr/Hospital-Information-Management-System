<?php

namespace App\Http\Requests\Doctors;

use App\Http\Requests\Clinics\ClinicQueryRequest;
use Illuminate\Validation\Rule;

class DoctorQueryRequest extends ClinicQueryRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'sort' => ['sometimes', Rule::in(['code', 'name', 'clinic_count', 'patient_count', 'is_active'])],
            'columns' => ['sometimes', 'array', 'min:1', 'max:10'],
            'columns.*' => ['string', 'distinct', Rule::in(['number', 'code', 'name', 'specialties', 'description', 'clinics', 'clinic_count', 'patient_count', 'is_active'])],
        ]);
    }
}
