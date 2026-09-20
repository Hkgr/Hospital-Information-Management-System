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
        return ['required' => 'هذا الحقل مطلوب.', 'present' => 'أرسل بيانات القسم ولو كانت فارغة.', 'integer' => 'أدخل عددًا صحيحًا صالحًا.', 'numeric' => 'أدخل قيمة رقمية صالحة.', 'gt' => 'يجب أن تكون القيمة موجبة.', 'date_format' => 'أدخل تاريخًا صالحًا.', 'after_or_equal' => 'تاريخ النهاية لا يسبق تاريخ البداية.', 'in' => 'اختر قيمة متاحة من القائمة.', 'max' => 'القيمة تتجاوز الحد المسموح.', 'uuid' => 'معرّف إعادة الطلب غير صالح.', 'decimal' => 'تقبل القيمة أربع منازل عشرية كحد أقصى.'];
    }

    public function operation(): string
    {
        $name = $this->route()?->getName() ?? '';
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
        $rules = ['facility_id' => $id, 'request_id' => ['required', 'uuid'], 'lock_version' => $this->route('plan') || $this->route('session') || $this->route('dose') || $this->route('dispensing') ? $id : ['sometimes', 'integer', 'min:1']];
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
        if ($op === 'schedule') {
            return $rules + ['sessions' => ['required', 'array', 'min:1', 'max:24'], 'sessions.*.planned_on' => $date, 'sessions.*.cycle_number' => $optionalId, 'sessions.*.session_number' => [...$id, 'distinct'], 'sessions.*.note' => $text];
        }
        if ($op === 'session') {
            return $rules + ['status' => ['required', 'in:rescheduled,missed,cancelled,referred'], 'planned_on' => [Rule::requiredIf(fn () => $this->input('status') === 'rescheduled' && ! $this->boolean('carry_forward')), 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'], 'plan_lock_version' => $id, 'carry_forward' => ['sometimes', 'boolean']];
        }
        if ($op === 'plan') {
            $rules += ['confirm_duplicate' => ['sometimes', 'boolean'], 'duplicate_reason' => ['required_if:confirm_duplicate,true', 'nullable', 'string', 'max:2000']];
            $rules += ['modality' => ['required', Rule::in(array_keys(OncologyQueries::MODALITIES))], 'intent' => ['required', Rule::in(array_keys(OncologyQueries::INTENTS))], 'protocol_name' => ['required', 'string', 'max:200'], 'protocol_code' => ['nullable', 'string', 'max:100'], 'clinic_id' => $id, 'doctor_id' => $id, 'diagnosis_id' => $optionalId, 'starts_on' => $date, 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'planned_cycles' => $optionalId, 'planned_sessions' => $optionalId, 'interval_days' => $optionalId, 'note' => $text, 'amendment_reason' => ['nullable', 'string', 'max:2000'], 'items' => ['present', 'array', 'max:100']];
        } elseif ($op === 'administer') {
            $rules += ['reporting_period_id' => $id, 'administered_on' => $date, 'supervising_staff_id' => $id, 'administered_by' => $id, 'session_label' => ['nullable', 'string', 'max:200'], 'note' => $text, 'items' => ['present', 'array', 'max:100']];
            if ($this->route('dose')) {
                $rules['reason'] = ['required', 'string', 'max:2000'];
            } else {
                $rules += ['session_id' => $id, 'session_lock_version' => $id, 'plan_lock_version' => $id, 'visit_lock_version' => $id];
            }
        } elseif ($op === 'dispense') {
            $rules += ['reporting_period_id' => $id, 'dispensed_on' => $date, 'prescribing_staff_id' => $id, 'dose_session_id' => $id, 'dispensing_purpose' => ['required', 'in:take_home,supportive']];
            if ($this->route('dispensing')) {
                $rules['reason'] = ['required', 'string', 'max:2000'];
            }
        }
        $prefix = $op === 'dispense' ? '' : 'items.*.';
        $rules += [$prefix.'medication_id' => $optionalId, $prefix.'medication_name_snapshot' => ['nullable', 'string', 'max:200'], $prefix.'medication_code_snapshot' => ['nullable', 'string', 'max:100'], $prefix.'funding_source_id' => $op === 'administer' ? $id : $optionalId, $prefix.'note' => $text];
        if ($op !== 'dispense') {
            $rules += [$prefix.'dose_value' => $positive, $prefix.'dose_unit' => ['required', 'string', 'max:40'], $prefix.'route' => ['required', 'string', 'max:100']];
        }
        if ($op === 'plan' && $this->route('plan')) {
            $rules['items'] = ['sometimes', 'array', 'max:100'];
        }
        if ($op === 'plan') {
            $rules[$prefix.'id'] = ['nullable', 'integer', 'min:1', 'distinct'];
        }
        if ($op === 'plan') {
            $rules[$prefix.'instructions'] = ['nullable', 'string', 'max:1000'];
        } else {
            $rules += [$prefix.'dose_text' => ['nullable', 'string', 'max:100'], $prefix.'quantity' => $positive, $prefix.'quantity_unit' => ['required', 'string', 'max:40']];
        }
        if ($op === 'administer') {
            $rules += ['items.*.id' => ['nullable', 'integer', 'min:1', 'distinct'], 'items.*.lock_version' => ['required_with:items.*.id', 'integer', 'min:1'], 'items.*.remove' => ['sometimes', 'boolean'], 'items.*.void_reason' => ['nullable', 'string', 'max:255']];
        }

        return $rules;
    }
}
