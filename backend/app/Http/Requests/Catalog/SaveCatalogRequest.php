<?php

namespace App\Http\Requests\Catalog;

use App\Services\Catalog\CatalogQueries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $kind = $creating ? $this->input('kind') : $this->route('kind');

        return ['facility_id' => ['required', 'integer', 'min:1'], 'kind' => [$creating ? 'required' : 'prohibited', Rule::in(CatalogQueries::kinds())],
            'code' => ['required', 'string', 'max:50'], 'name_ar' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['required', 'boolean'], 'lock_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'category_id' => [$kind === 'service' ? 'required' : ($kind === 'medication' ? 'nullable' : 'prohibited'), 'integer', 'min:1'],
            'procedure_type_id' => [$kind === 'procedure' ? 'nullable' : 'prohibited', 'integer', 'min:1'],
            'default_unit' => [$kind === 'medication' ? 'nullable' : 'prohibited', 'string', 'max:40'],
            'strength' => [$kind === 'medication' ? 'nullable' : 'prohibited', 'string', 'max:60'],
            'dosage_form' => [$kind === 'medication' ? 'nullable' : 'prohibited', 'string', 'max:40'],
            'reorder_level' => [$kind === 'medication' ? 'nullable' : 'prohibited', 'numeric', 'min:0']];
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'max' => 'القيمة أطول من الحد المسموح (:max).', 'integer' => 'اختر قيمة صحيحة.',
            'boolean' => 'الحالة غير صالحة.', 'prohibited' => 'لا يمكن تغيير هذا الحقل في هذا الطلب.', 'in' => 'القيمة غير مسموحة.'];
    }
}
