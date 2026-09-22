<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

class SaveStockDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $store = $this->route('directory') === 'stores';

        return [
            'facility_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:50'],
            'name_ar' => ['required', 'string', 'max:200'],
            'is_active' => ['required', 'boolean'],
            'lock_version' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'location' => [$store ? 'nullable' : 'prohibited', 'string', 'max:200'],
            'contact_person' => [$store ? 'prohibited' : 'nullable', 'string', 'max:120'],
            'phone' => [$store ? 'prohibited' : 'nullable', 'string', 'max:30'],
            'address_line' => [$store ? 'prohibited' : 'nullable', 'string', 'max:255'],
            'note' => [$store ? 'prohibited' : 'nullable', 'string', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'max' => 'القيمة أطول من الحد المسموح (:max).', 'prohibited' => 'لا يمكن استخدام هذا الحقل في هذا الطلب.'];
    }
}
