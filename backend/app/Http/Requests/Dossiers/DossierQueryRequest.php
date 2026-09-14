<?php

namespace App\Http\Requests\Dossiers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DossierQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'facility_id' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['all', 'draft', 'active'])],
            'search' => ['nullable', 'string', 'max:200'],
            'oncology' => ['nullable', Rule::in(['yes', 'no'])],
            'visits' => ['nullable', Rule::in(['with', 'without'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'sort' => ['sometimes', Rule::in(['code', 'patient_name', 'opening_date', 'visit_count'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', Rule::in([10, 20, 50, 100])],
        ];
    }
}
