<?php

namespace App\Http\Requests\Dossiers;

use Illuminate\Foundation\Http\FormRequest;

class SaveDiagnosticAssessment extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['required_if' => 'هذا الحقل مطلوب للقرار التشخيصي المختار.', 'required_with' => 'اختر العيادة والطبيب معًا أو اتركهما فارغين.', 'date_format' => 'أدخل تاريخًا صحيحًا بصيغة سنة-شهر-يوم.'];
    }

    public function rules(): array
    {
        return [
            'facility_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:0'],
            'disposition' => ['required', 'in:not_assessed,pathology_required,pathology_pending,pathology_confirmed,pathology_not_required,referred_out'],
            'assessed_on' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:20000'],
            'required_reason' => ['nullable', 'required_if:disposition,pathology_required', 'string', 'max:2000'],
            'not_required_reason' => ['nullable', 'required_if:disposition,pathology_not_required', 'string', 'max:2000'],
            'follow_up' => ['nullable', 'string', 'max:2000'],
            'clinic_id' => ['nullable', 'required_with:doctor_id', 'integer', 'min:1'], 'doctor_id' => ['nullable', 'required_with:clinic_id', 'integer', 'min:1'],
            'evidence_pathology_id' => ['nullable', 'required_if:disposition,pathology_confirmed', 'integer', 'min:1'],
        ];
    }
}
