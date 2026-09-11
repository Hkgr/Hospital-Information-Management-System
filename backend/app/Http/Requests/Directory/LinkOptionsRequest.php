<?php

namespace App\Http\Requests\Directory;

use App\Http\Requests\Clinics\ClinicQueryRequest;

class LinkOptionsRequest extends ClinicQueryRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // A batch is a complete lookup, never a search or a paginated subset.
            'ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'search' => ['prohibited_with:ids', 'nullable', 'string', 'max:200'],
            'page' => ['prohibited_with:ids', 'sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['prohibited_with:ids', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
