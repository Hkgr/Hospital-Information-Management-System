<?php

namespace App\Services\Dossiers;

use Illuminate\Support\Facades\DB;

class PatientCardCodes
{
    public function reserve(): object
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Patient-code reservations require an enclosing transaction.');
        }
        $key = ['sequence_key' => 'patient_card', 'scope_key' => 'global', 'period_key' => 'all'];
        DB::table('number_sequences')->insertOrIgnore($key);

        return DB::table('number_sequences')->where($key)->lockForUpdate()->first();
    }

    public function next(): string
    {
        $sequence = $this->reserve();
        $n = $sequence->current_value;
        do {
            $code = 'PC-'.str_pad((string) ++$n, 8, '0', STR_PAD_LEFT);
        } while (DB::table('patients')->where('patient_code', $code)->exists() || DB::table('patient_dossiers')->where('code', $code)->exists());
        DB::table('number_sequences')->where('id', $sequence->id)->update(['current_value' => $n, 'updated_at' => now()]);

        return $code;
    }
}
