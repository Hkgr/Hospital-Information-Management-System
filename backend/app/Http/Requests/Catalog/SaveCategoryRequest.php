<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['required', 'integer', 'min:1'], 'kind' => ['sometimes', Rule::in(['service', 'procedure', 'medication'])],
            'code' => ['required', 'string', 'max:50'],
            'name_ar' => ['required', 'string', 'max:200'], 'is_active' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'max' => 'القيمة أطول من الحد المسموح (:max).', 'boolean' => 'الحالة غير صالحة.'];
    }
}
