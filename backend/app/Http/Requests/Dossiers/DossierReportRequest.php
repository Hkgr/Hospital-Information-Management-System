<?php

namespace App\Http\Requests\Dossiers;

use App\Services\Dossiers\DossierReports;
use Illuminate\Validation\Rule;

class DossierReportRequest extends DossierQueryRequest
{
    public function rules(): array
    {
        return parent::rules() + ['columns' => ['sometimes', 'array', 'min:1', 'max:11'], 'columns.*' => ['required', 'string', 'distinct', Rule::in(array_keys(DossierReports::COLUMNS))]];
    }
}
