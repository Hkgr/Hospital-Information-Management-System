<?php

namespace App\Http\Requests\BloodBank;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BloodEventQuery extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:200'],
            'kind' => ['nullable', Rule::in(['donation', 'benefit'])], 'benefit_kind' => ['nullable', Rule::in(['issue', 'transfusion'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'blood_component_id' => ['nullable', 'integer', 'min:1'], 'clinic_id' => ['nullable', 'integer', 'min:1'], 'person_id' => ['nullable', 'integer', 'min:1'],
            'blood_group' => ['nullable', Rule::in(['A', 'B', 'AB', 'O'])], 'rh' => ['nullable', Rule::in(['positive', 'negative'])],
            'available_issues' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', Rule::in([10, 20, 50, 100])],
            'sort' => ['sometimes', Rule::in(['code', 'occurred_on', 'name', 'quantity'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])]];
    }
}
