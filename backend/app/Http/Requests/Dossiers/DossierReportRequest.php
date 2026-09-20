<?php

namespace App\Http\Requests\Dossiers;

use App\Services\Dossiers\DossierReports;
use Illuminate\Validation\Rule;

class DossierReportRequest extends DossierQueryRequest
{
    protected function prepareForValidation(): void
    {
        $columns = $this->input('columns');
        if (is_array($columns) && count(array_filter($columns, 'is_string')) === count($columns)) {
            // Compatibility for saved export selections: one canonical column.
            $this->merge(['columns' => array_values(array_unique(array_map(fn ($c) => $c === 'patient_code' ? 'code' : $c, $columns)))]);
        }
    }

    public function rules(): array
    {
        return parent::rules() + ['columns' => ['sometimes', 'array', 'min:1', 'max:'.count(DossierReports::COLUMNS)], 'columns.*' => ['required', 'string', 'distinct', Rule::in(array_keys(DossierReports::COLUMNS))]];
    }
}
