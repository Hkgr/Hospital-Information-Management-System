<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;

class SaveStockReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'facility_id' => ['required', 'integer', 'min:1'],
            'request_id' => [$creating ? 'required' : 'prohibited', 'uuid'],
            'lock_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'store_id' => ['required', 'integer', 'min:1'],
            'receipt_no' => ['required', 'string', 'max:50'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'funding_source_id' => ['required', 'integer', 'min:1'],
            'received_on' => ['required', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:10000'],
            'items' => ['sometimes', 'array', 'max:200'],
            'items.*.medication_id' => ['required', 'integer', 'min:1'],
            'items.*.batch_number' => ['required', 'string', 'max:60'],
            'items.*.expiry_date' => ['required', 'date'],
            'items.*.manufactured_on' => ['nullable', 'date'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'uuid' => 'معرّف الحفظ غير صالح.', 'gt' => 'الكمية يجب أن تكون أكبر من صفر.'];
    }
}
