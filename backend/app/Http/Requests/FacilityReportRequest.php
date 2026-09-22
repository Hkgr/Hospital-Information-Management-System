<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FacilityReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $custom = $this->input('period') === 'custom';

        return [
            'facility_id' => ['required', 'integer', 'min:1'],
            'period' => ['required', 'in:day,week,custom'],
            'from' => [$custom ? 'required' : 'prohibited', 'date_format:Y-m-d'],
            'to' => [$custom ? 'required' : 'prohibited', 'date_format:Y-m-d', ...($custom ? ['after_or_equal:from'] : [])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('period') !== 'custom' || $validator->errors()->isNotEmpty()) {
                return;
            }
            $from = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $this->input('from'));
            $to = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $this->input('to'));
            if ($from && $to && $from->diff($to)->days > 365) {
                $validator->errors()->add('to', 'الفترة المخصصة لا تتجاوز 366 يومًا.');
            }
        });
    }
}
