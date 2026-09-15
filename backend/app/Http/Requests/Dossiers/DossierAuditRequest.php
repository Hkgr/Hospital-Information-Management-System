<?php

namespace App\Http\Requests\Dossiers;

use App\Services\Dossiers\DossierAuditHistory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DossierAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['required', 'integer', 'min:1'], 'visit_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'entity' => ['nullable', Rule::in(array_keys(DossierAuditHistory::ENTITIES))], 'action' => ['nullable', Rule::in(array_keys(DossierAuditHistory::ACTIONS))],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', Rule::in([10, 20, 50, 100])]];
    }
}
