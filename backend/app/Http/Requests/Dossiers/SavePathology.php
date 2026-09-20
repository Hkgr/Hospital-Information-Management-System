<?php

namespace App\Http\Requests\Dossiers;

use Illuminate\Foundation\Http\FormRequest;

class SavePathology extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['required_if' => 'هذا الحقل مطلوب للحالة أو المصدر المختار.', 'required_with' => 'اختر العيادة والطبيب معًا أو اتركهما فارغين.', 'date_format' => 'أدخل تاريخًا صحيحًا بصيغة سنة-شهر-يوم.'];
    }

    public function rules(): array
    {
        $rules = ['facility_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'lock_version' => [$this->isMethod('PUT') || $this->route('pathology') ? 'required' : 'sometimes', 'integer', 'min:1']];
        if ($this->routeIs('dossiers.pathology.void')) {
            return $rules + ['void_reason' => ['required', 'string', 'max:255']];
        }

        return $rules + [
            'source' => ['required', 'in:internal,external'],
            'status' => ['required', 'in:requested,specimen_collected,pending_result,completed,unavailable,cancelled'],
            'report_number' => ['nullable', 'string', 'max:100'],
            'external_organization' => ['nullable', 'required_if:source,external', 'string', 'max:200'],
            'specimen_type' => ['nullable', 'string', 'max:200'], 'anatomical_site' => ['nullable', 'string', 'max:200'],
            'procedure_event_id' => ['nullable', 'integer', 'min:1'],
            'clinic_id' => ['nullable', 'required_with:doctor_id', 'integer', 'min:1'], 'doctor_id' => ['nullable', 'required_with:clinic_id', 'integer', 'min:1'],
            'requested_on' => ['nullable', 'date_format:Y-m-d'], 'collected_on' => ['nullable', 'date_format:Y-m-d'], 'result_on' => ['nullable', 'required_if:status,completed', 'date_format:Y-m-d'],
            'conclusion' => ['nullable', 'required_if:status,completed', 'string', 'max:20000'], 'note' => ['nullable', 'string', 'max:20000'],
            'unavailable_reason' => ['nullable', 'required_if:status,unavailable,cancelled', 'string', 'max:2000'],
            'attachment_ids' => ['sometimes', 'array', 'max:100'], 'attachment_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
