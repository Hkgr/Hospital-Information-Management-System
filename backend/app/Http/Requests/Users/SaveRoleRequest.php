<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name_ar'))) {
            $this->merge(['name_ar' => trim($this->input('name_ar'))]);
        }
        $ids = $this->input('permission_ids');
        if (is_array($ids)) {
            $this->merge(['permission_ids' => array_values(array_unique(array_map('intval', $ids)))]);
        }
    }

    public function rules(): array
    {
        return [
            'facility_id' => ['required', 'integer', 'min:1'],
            'name_ar' => ['required', 'string', 'max:200'],
            'permission_ids' => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', 'min:1'],
        ];
    }
}
