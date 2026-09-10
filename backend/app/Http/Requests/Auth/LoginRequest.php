<?php

namespace App\Http\Requests\Auth;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('username', description: 'Username only; email login is not supported.', example: 'admin')]
#[BodyParameter('password', format: 'password', example: 'example-password')]
#[BodyParameter('device_name', default: 'hospital-web', example: 'hospital-web')]
class LoginRequest extends FormRequest
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
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
