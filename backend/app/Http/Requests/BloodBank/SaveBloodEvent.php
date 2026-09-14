<?php

namespace App\Http\Requests\BloodBank;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBloodEvent extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['facility_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'],
            'lock_version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'person_id' => ['required_without:person', 'prohibits:person', 'integer', 'min:1'], 'person' => ['required_without:person_id', 'prohibits:person_id', 'array'],
            'kind' => ['required', Rule::in(['donation', 'benefit'])],
            'benefit_kind' => ['required_if:kind,benefit', 'prohibited_if:kind,donation', Rule::in(['issue', 'transfusion'])],
            'benefit_link_mode' => ['required_if:benefit_kind,transfusion', 'prohibited_unless:benefit_kind,transfusion', Rule::in(['independent', 'linked'])],
            'issue_event_id' => ['required_if:benefit_link_mode,linked', 'nullable', 'integer', 'min:1', 'prohibited_unless:benefit_link_mode,linked'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'quantity' => ['required', 'numeric', 'gt:0', 'regex:/\A[0-9]{1,14}(?:\.[0-9]{1,4})?\z/'],
            'quantity_unit' => ['required', Rule::in(['kg', 'unit'])],
            'blood_component_id' => ['required', 'integer', 'min:1'], 'clinic_id' => ['required', 'integer', 'min:1'], 'responsible_staff_id' => ['required', 'integer', 'min:1'],
            'blood_group' => ['nullable', Rule::in(['A', 'B', 'AB', 'O'])], 'rh' => ['nullable', Rule::in(['positive', 'negative'])],
            'beneficiary_entity' => ['nullable', 'string', 'max:200', 'prohibited_if:kind,donation'], 'entity_address' => ['nullable', 'string', 'max:255', 'prohibited_if:kind,donation'],
            'screenings' => ['sometimes', 'array', 'max:3'], 'screenings.*' => ['array:analyte,status'],
            'screenings.*.analyte' => ['required', 'distinct', Rule::in(['HBsAg', 'HCV', 'HIV'])],
            'screenings.*.status' => ['required', Rule::in(['not_requested', 'requested', 'pending', 'complete', 'cancelled'])]];
    }

    public function messages(): array
    {
        return ['quantity.required' => 'أدخل الكمية (كغ) دون قيمة افتراضية.', 'quantity.gt' => 'الكمية يجب أن تكون موجبة.', 'quantity.regex' => 'أدخل كمية عشرية موجبة بأربعة منازل عشرية كحد أقصى.', 'benefit_kind.required_if' => 'حدد هل الاستفادة صرف مكوّن أم نقل دم أُجري فعليًا.', 'required' => 'هذا الحقل مطلوب.'];
    }
}
