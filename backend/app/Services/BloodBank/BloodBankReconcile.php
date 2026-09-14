<?php

namespace App\Services\BloodBank;

use App\Http\Requests\BloodBank\SaveBloodProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BloodBankReconcile
{
    public function inventory(): array
    {
        $counts = [];
        foreach (['blood_donors', 'blood_recipients', 'blood_donations', 'blood_transfusions', 'blood_bank_people', 'blood_bank_events', 'blood_bank_identity_reviews'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['unmapped_profiles'] = DB::table('blood_donors')->whereNull('person_id')->count() + DB::table('blood_recipients')->whereNull('person_id')->count();

        return $counts;
    }

    public function apply(): array
    {
        return DB::transaction(function () {
            // Exclusive ordered facility locks serialize reconciliation with event writes.
            DB::table('facilities')->orderBy('id')->lockForUpdate()->get(['id']);
            $before = $this->inventory();
            foreach (['blood_donors', 'blood_recipients'] as $table) {
                DB::table($table)->whereNull('person_id')->orderBy('id')->chunkById(250, function ($rows) use ($table) {
                    foreach ($rows as $r) {
                        $id = $this->person((array) $r, $table);
                        DB::table($table)->where('id', $r->id)->update(['person_id' => $id]);
                    }
                });
            }
            foreach (['blood_donations' => 'donation', 'blood_transfusions' => 'benefit'] as $table => $kind) {
                $fk = $kind === 'donation' ? 'blood_donation_id' : 'blood_transfusion_id';
                DB::table($table)->whereNotIn('id', DB::table('blood_bank_events')->whereNotNull($fk)->select($fk))->orderBy('id')->chunkById(250, function ($rows) use ($kind, $fk) {
                    foreach ($rows as $r) {
                        $person = $kind === 'donation' ? DB::table('blood_donors')->where('id', $r->donor_id)->value('person_id') : $this->person((array) $r, 'blood_transfusions');
                        $code = $kind === 'donation' ? $r->donation_code : 'TRF-'.str_replace('-', '', $r->transfused_on).'-'.str_pad((string) $r->id, 6, '0', STR_PAD_LEFT);
                        $event = DB::table('blood_bank_events')->insertGetId([
                            'person_id' => $person, 'facility_id' => $r->facility_id, 'kind' => $kind, 'benefit_kind' => $kind === 'benefit' ? 'transfusion' : null, $fk => $r->id,
                            'code' => $code, 'occurred_on' => $kind === 'donation' ? $r->donated_on : $r->transfused_on, 'reporting_period_id' => $r->reporting_period_id,
                            'blood_component_id' => $r->blood_component_id ?? null, 'blood_group' => $r->blood_group, 'rh' => $r->rh,
                            'quantity' => $r->units, 'quantity_unit' => $r->quantity_unit, 'beneficiary_entity' => $r->beneficiary_entity ?? null, 'legacy_address' => $kind === 'benefit' ? $r->address_line : null,
                            'status' => $r->status ?? 'recorded', 'voided_at' => $r->voided_at, 'legacy' => true, 'entered_by' => $r->entered_by, 'updated_by' => $r->updated_by,
                            'lock_version' => $r->lock_version, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                        ]);
                        $codes = $kind === 'donation' ? DB::table('blood_donation_codes')->where('blood_donation_id', $r->id)->pluck('code')->all() : [];
                        foreach (array_unique([$code, ...$codes]) as $alias) {
                            DB::table('blood_bank_event_codes')->insert(['event_id' => $event, 'code' => $alias, 'created_at' => now()]);
                        }
                        // Original donation screenings stay attached to their original IDs.
                        // Profile screenings have no event evidence and are never assigned here.
                    }
                });
            }

            return ['before' => $before, 'after' => $this->inventory(), 'reviews' => DB::table('blood_bank_identity_reviews')->orderBy('id')->get(['source', 'source_id', 'reason', 'references'])->all()];
        }, 3);
    }

    private function person(array $r, string $source): int
    {
        $patient = $r['patient_id'] ?? null;
        $old = $patient ? DB::table('blood_bank_people')->where('facility_id', $r['facility_id'])->where('patient_id', $patient)->lockForUpdate()->first() : null;
        if ($old) {
            if ($source !== 'blood_transfusions' && ($old->blood_group !== $r['blood_group'] || $old->rh !== $r['rh'])) {
                DB::table('blood_bank_people')->where('id', $old->id)->update(['blood_group' => null, 'rh' => null]);
                $this->review($source, $r['id'], 'conflicting_current_blood_type', ['person_id' => $old->id]);
            }
            if (isset($r['is_active']) && ! $r['is_active']) {
                DB::table('blood_bank_people')->where('id', $old->id)->update(['is_active' => false]);
            }

            return $old->id;
        }
        $fields = ['facility_id' => $r['facility_id'], 'patient_id' => $patient, 'code' => (string) Str::uuid(), 'entered_by' => $r['entered_by'], 'is_active' => $r['is_active'] ?? true, 'created_at' => $r['created_at'] ?? now(), 'updated_at' => $r['updated_at'] ?? now()];
        if (! $patient) {
            $fields += array_intersect_key($r, array_flip([...SaveBloodProfile::PERSON, ...SaveBloodProfile::MANUAL_ADDRESS, 'national_id']));
            if ($source === 'blood_transfusions') {
                // This historical address has an ambiguous meaning; leave it on its event.
                $fields = array_diff_key($fields, array_flip([...SaveBloodProfile::PERSON, ...SaveBloodProfile::MANUAL_ADDRESS]));
                $fields['legacy_name'] = $r['external_recipient_name'];
                $this->review($source, $r['id'], 'external_name_identity_unconfirmed');
            } else {
                $fields['legacy_name'] = $r['full_name'] ?? null;
                $this->review($source, $r['id'], 'direct_identity_requires_explicit_selection');
            }
        }
        if ($source !== 'blood_transfusions') {
            $fields += ['blood_group' => $r['blood_group'], 'rh' => $r['rh']];
        }
        $id = DB::table('blood_bank_people')->insertGetId($fields);
        DB::table('blood_bank_people')->where('id', $id)->update(['code' => 'BP-'.str_pad((string) $id, 8, '0', STR_PAD_LEFT)]);

        return $id;
    }

    private function review(string $source, int $id, string $reason, array $references = []): void
    {
        DB::table('blood_bank_identity_reviews')->insertOrIgnore(['source' => $source, 'source_id' => $id, 'reason' => $reason, 'references' => json_encode($references), 'created_at' => now(), 'updated_at' => now()]);
    }
}
