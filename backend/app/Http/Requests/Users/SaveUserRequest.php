<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => trim($this->input('username'))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
        if (is_string($this->input('email'))) {
            $email = trim($this->input('email'));
            $this->merge(['email' => $email === '' ? null : $email]);
        }
    }

    public function rules(): array
    {
        return [
            'facility_id' => ['required', 'integer', 'min:1'],
            'username' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'role_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
