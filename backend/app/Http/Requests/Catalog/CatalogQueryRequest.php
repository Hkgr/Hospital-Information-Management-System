<?php

namespace App\Http\Requests\Catalog;

use App\Services\Catalog\CatalogQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['required', 'integer', 'min:1'], 'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'kind' => ['sometimes', Rule::in(CatalogQueries::kinds())], 'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
            'sort' => ['sometimes', Rule::in(['code', 'name_ar', 'kind', 'is_active', 'patient_count'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', Rule::in([10, 20, 50, 100])],
            'columns' => ['sometimes', 'array', 'min:1', 'max:7'], 'columns.*' => ['string', 'distinct', Rule::in(array_keys(CatalogQueries::COLUMNS))]];
    }
}
