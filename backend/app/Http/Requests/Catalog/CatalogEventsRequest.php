<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')],
            'sort' => ['sometimes', Rule::in(['performed_on', 'patient_code', 'patient_name', 'visit_no'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', Rule::in([10, 20, 50, 100])]];
    }
}
