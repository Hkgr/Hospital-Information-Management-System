<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (array_diff(array_keys($this->all()), ['facility_id'])) {
                $validator->errors()->add('query', 'يسمح فقط بمعرّف المنشأة facility_id.');
            }
        }];
    }

    public function facilityId(): ?int
    {
        return isset($this->validated()['facility_id']) ? (int) $this->validated()['facility_id'] : null;
    }
}
