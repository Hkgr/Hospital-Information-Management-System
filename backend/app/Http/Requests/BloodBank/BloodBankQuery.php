<?php

namespace App\Http\Requests\BloodBank;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BloodBankQuery extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:200'],
            'kind' => ['sometimes', Rule::in(['donor', 'recipient'])], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', Rule::in([10, 20, 50, 100])], 'sort' => ['sometimes', Rule::in(['code', 'name', 'updated_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])], 'clinic_id' => ['nullable', 'integer', 'min:1'],
            'governorate_id' => ['nullable', 'integer', 'min:1']];
    }
}
