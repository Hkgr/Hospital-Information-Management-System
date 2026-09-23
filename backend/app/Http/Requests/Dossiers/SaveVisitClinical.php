<?php

namespace App\Http\Requests\Dossiers;

use Database\Seeders\DossierOutcomeSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVisitClinical extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rx = $this->input('prescription');
        if (is_array($rx) && empty($rx['kind'])) {
            $this->merge(['prescription' => $rx + ['kind' => 'unlinked']]);
        }
    }

    public function rules(): array
    {
        $rules = ['facility_id' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:1'], 'unchanged' => ['sometimes', 'boolean']];
        $row = ['id' => ['nullable', 'integer', 'min:1'], 'lock_version' => ['required_with:ROW.id', 'integer', 'min:1'], 'remove' => ['sometimes', 'boolean'], 'void_reason' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:10000']];
        if ($this->route('section') === 'clinical') {
            foreach (['services', 'procedures'] as $section) {
                $rules[$section] = ['present', 'array', 'max:100'];
                foreach ($row + ['catalog_id' => ['required', 'integer', 'min:1'], 'clinic_id' => ['required', 'integer', 'min:1'], 'doctor_id' => ['required', 'integer', 'min:1']] as $key => $value) {
                    $rules["$section.*.$key"] = str_replace('ROW', "$section.*", $value);
                }
                $rules["$section.*.id"][] = 'distinct';
            }
        } else {
            $rules['prescription'] = ['present', 'nullable', 'array'];
            $rules['outcome'] = ['present', 'nullable', 'array'];
            if ($this->input('prescription') !== null) {
                $kind = $this->input('prescription.kind', 'unlinked');
                foreach ($row + ['kind' => ['required', 'in:unlinked,dose_linked,outside'], 'prescribing_clinic_id' => ['required', 'integer', 'min:1'], 'prescribing_staff_id' => ['required', 'integer', 'min:1'], 'prescribed_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'], 'funding_source_id' => [$kind === 'dose_linked' ? 'required' : 'prohibited', 'nullable', 'integer', 'min:1'], 'unavailable_reason' => [$kind === 'outside' ? 'required' : 'prohibited', 'nullable', 'string', 'max:10000'], 'items' => ['present', 'array', 'max:100']] as $key => $value) {
                    $rules["prescription.$key"] = str_replace('ROW', 'prescription', $value);
                }
                foreach ($row + ['medication_id' => ['required', 'integer', 'min:1'], 'display_order' => ['required', 'integer', 'min:0', 'max:1000']] as $key => $value) {
                    $rules["prescription.items.*.$key"] = str_replace('ROW', 'prescription.items.*', $value);
                }
                $rules['prescription.items.*.id'][] = 'distinct';
            }
            if ($this->input('outcome') !== null) {
                foreach ($row + ['code' => ['required', Rule::in(array_keys(DossierOutcomeSeeder::OUTCOMES))], 'clinic_id' => ['required', 'integer', 'min:1'], 'doctor_id' => ['required', 'integer', 'min:1'], 'outcome_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01']] as $key => $value) {
                    $rules["outcome.$key"] = str_replace('ROW', 'outcome', $value);
                }
                $referral = $this->input('outcome.code') === 'DOS-REFER';
                foreach (['referral_target', 'outgoing_referral_date', 'outgoing_referral_reason'] as $key) {
                    $rules["outcome.$key"] = [$referral ? 'required' : 'prohibited', 'nullable', $key === 'outgoing_referral_date' ? 'date_format:Y-m-d' : 'string', $key === 'outgoing_referral_date' ? 'after_or_equal:1000-01-01' : ($key === 'referral_target' ? 'max:200' : 'max:10000')];
                }
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return (new SaveDossierSection)->messages();
    }
}
