<?php

namespace App\Http\Requests\Dossiers;

use App\Services\Dossiers\OncologyIntegrity;
use App\Services\Dossiers\OncologyQueries;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOncology extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        if ($this->operation() === 'void' && $this->route('dose') && ($validator->errors()->has('session_resolution') || $validator->errors()->has('planned_on'))) {
            OncologyIntegrity::reject('ONCOLOGY_INVALID_VOID_RESOLUTION', 'حدد معالجة الموعد وتاريخ إعادة الجدولة عند إبطال الإعطاء.', ['fields' => $validator->errors()->toArray()]);
        }
        parent::failedValidation($validator);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['planned_on.required_if' => 'حدد تاريخًا صريحًا لإعادة الجدولة.', 'required' => 'هذا الحقل مطلوب.', 'present' => 'أرسل بيانات القسم ولو كانت فارغة.', 'integer' => 'أدخل عددًا صحيحًا صالحًا.', 'numeric' => 'أدخل قيمة رقمية صالحة.', 'gt' => 'يجب أن تكون القيمة موجبة.', 'date_format' => 'أدخل تاريخًا صالحًا.', 'after_or_equal' => 'تاريخ النهاية لا يسبق تاريخ البداية.', 'in' => 'اختر قيمة متاحة من القائمة.', 'max' => 'القيمة تتجاوز الحد المسموح.', 'uuid' => 'معرّف إعادة الطلب غير صالح.', 'decimal' => 'تقبل القيمة أربع منازل عشرية كحد أقصى.'];
    }

    public function operation(): string
    {
        $name = $this->route()?->getName() ?? '';
        if (str_contains($name, 'session-dose')) {
            return 'session-dose';
        }
        if (str_ends_with($name, '.appointment')) {
            return 'appointment';
        }
        if (str_ends_with($name, '.void')) {
            return 'void';
        }
        if (str_contains($name, '.doses.')) {
            return 'administer';
        }
        if (str_contains($name, '.dispensing.')) {
            return 'dispense';
        }
        if (str_ends_with($name, '.status')) {
            return 'status';
        }
        if (str_ends_with($name, '.schedule')) {
            return 'schedule';
        }
        if (str_ends_with($name, '.reschedule')) {
            return 'session';
        }

        return 'plan';
    }

    public function rules(): array
    {
        $op = $this->operation();
        $id = ['required', 'integer', 'min:1'];
        $optionalId = ['nullable', 'integer', 'min:1'];
        $date = ['required', 'date_format:Y-m-d'];
        $positive = ['required', 'numeric', 'gt:0', 'max:99999999999999', 'decimal:0,4'];
        $text = ['nullable', 'string', 'max:10000'];
        $versioned = $op === 'session-dose' ? (bool) $this->route('sessionDose') : ($this->route('plan') || $this->route('session') || $this->route('dose') || $this->route('dispensing'));
        $rules = ['facility_id' => $id, 'request_id' => ['required', 'uuid'], 'lock_version' => $versioned ? $id : ['sometimes', 'integer', 'min:1']];
        if ($op === 'void') {
            return $rules + ['reason' => ['required', 'string', 'max:255']] + ($this->route('dose') ? [
                'session_resolution' => ['required', 'in:rescheduled,missed,cancelled,referred'],
                'planned_on' => ['required_if:session_resolution,rescheduled', 'nullable', 'date_format:Y-m-d'],
                'session_lock_version' => $id, 'plan_lock_version' => $id, 'carry_forward' => ['sometimes', 'boolean'],
            ] : []);
        }
        if ($op === 'status') {
            return $rules + ['status' => ['required', 'in:active,paused,completed,cancelled'], 'reason' => ['required', 'string', 'max:2000'], 'override_reason' => ['nullable', 'string', 'max:2000']];
        }
        if ($op === 'appointment') {
            return $rules + ['planned_on' => $date, 'clinic_id' => $id, 'doctor_id' => $id, 'note' => $text];
        }
        if ($op === 'session-dose') {
            $creating = ! $this->route('sessionDose');
            return $rules + ['given_on' => $date, 'dose_name' => ['required', 'string', 'max:200'], 'complaint' => ['required', 'string', 'max:10000'], 'recommendations' => ['required', 'string', 'max:10000'], 'nurse_id' => $id, 'medication_source' => [$creating ? 'required' : 'nullable', 'string', Rule::in(array_keys(OncologyQueries::MEDICATION_SOURCES))]];
        }
        if ($op === 'schedule') {
            return $rules + ['sessions' => ['required', 'array', 'min:1', 'max:24'], 'sessions.*.planned_on' => $date, 'sessions.*.session_number' => ['sometimes', 'nullable', 'integer', 'min:1'], 'sessions.*.note' => $text];
        }
        if ($op === 'session') {
            return $rules + ['status' => ['required', 'in:rescheduled,missed,cancelled,referred'], 'planned_on' => ['required_if:status,rescheduled', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'], 'plan_lock_version' => $optionalId, 'carry_forward' => ['sometimes', 'boolean']];
        }
        if ($op === 'plan') {
            return $rules + ['confirm_duplicate' => ['sometimes', 'boolean'], 'duplicate_reason' => ['required_if:confirm_duplicate,true', 'nullable', 'string', 'max:2000'], 'modality' => ['required', Rule::in(array_keys(OncologyQueries::MODALITIES))], 'intent' => ['required', Rule::in(array_keys(OncologyQueries::INTENTS))], 'protocol_text' => ['required', 'string', 'max:20000'], 'protocol_clinic_id' => $id, 'protocol_doctor_id' => $id, 'treating_clinic_id' => $id, 'treating_doctor_id' => $id];
        }
        if ($op === 'administer') {
            $rules += ['reporting_period_id' => $optionalId, 'administered_on' => $date, 'supervising_staff_id' => $id, 'administered_by' => $id, 'session_label' => ['nullable', 'string', 'max:200'], 'note' => $text, 'items' => ['present', 'array', 'max:100']];
            if ($this->route('dose')) {
                $rules['reason'] = ['required', 'string', 'max:2000'];
            } else {
                $rules += ['session_id' => $id, 'session_lock_version' => $id, 'plan_lock_version' => $id, 'visit_lock_version' => $id];
            }
        } elseif ($op === 'dispense') {
            $free = in_array($this->input('dispensing_purpose'), ['unlinked', 'take_home'], true);
            $rules += [
                'reporting_period_id' => $optionalId, 'dispensed_on' => $date, 'prescribing_staff_id' => $id,
                'dispensing_purpose' => ['required', 'in:take_home,supportive,unlinked'],
                'dose_session_id' => ['nullable', 'integer', 'min:1', Rule::requiredIf(fn () => $this->input('dispensing_purpose') === 'supportive'), Rule::prohibitedIf(fn () => ! $this->route('dispensing') && $free)],
                'prescribing_clinic_id' => ['nullable', 'integer', 'min:1', Rule::requiredIf(fn () => $free && ! $this->filled('dose_session_id')), Rule::prohibitedIf(fn () => $this->input('dispensing_purpose') === 'supportive')],
            ];
            if ($this->route('dispensing')) {
                $rules['reason'] = ['required', 'string', 'max:2000'];
            }
        }
        $prefix = $op === 'dispense' ? '' : 'items.*.';
        $creating = ($op === 'administer' && ! $this->route('dose')) || ($op === 'dispense' && ! $this->route('dispensing'));
        $source = [$creating ? 'required' : 'nullable', 'string', Rule::in(array_keys(OncologyQueries::MEDICATION_SOURCES))];
        $rules += [$prefix.'medication_id' => $optionalId, $prefix.'medication_name_snapshot' => ['nullable', 'string', 'max:200'], $prefix.'medication_code_snapshot' => ['nullable', 'string', 'max:100'], $prefix.'funding_source_id' => $optionalId, $prefix.'medication_source' => $source, $prefix.'note' => $text];
        if ($op !== 'dispense') {
            $rules += [$prefix.'dose_value' => $positive, $prefix.'dose_unit' => ['required', 'string', 'max:40'], $prefix.'route' => ['required', 'string', 'max:100']];
        }
        $rules += [$prefix.'dose_text' => ['nullable', 'string', 'max:100'], $prefix.'quantity' => $positive, $prefix.'quantity_unit' => ['required', 'string', 'max:40']];
        if ($op === 'administer') {
            $rules += ['items.*.id' => ['nullable', 'integer', 'min:1', 'distinct'], 'items.*.lock_version' => ['required_with:items.*.id', 'integer', 'min:1'], 'items.*.remove' => ['sometimes', 'boolean'], 'items.*.void_reason' => ['nullable', 'string', 'max:255']];
        }

        return $rules;
    }
}
