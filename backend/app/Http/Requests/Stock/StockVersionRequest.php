<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

class StockVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:1']];
    }
}
