<?php

namespace App\Http\Requests\BloodBank;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDonation extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'],
            'lock_version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'donated_on' => ['required', 'date_format:Y-m-d'], 'blood_group' => ['required', Rule::in(['A', 'B', 'AB', 'O'])],
            'rh' => ['required', Rule::in(['positive', 'negative'])], 'units' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999999999.9999']];
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'in' => 'اختر قيمة معتمدة.', 'gt' => 'يجب أن يكون عدد الوحدات أكبر من صفر.', 'date_format' => 'أدخل تاريخًا صالحًا.'];
    }
}
