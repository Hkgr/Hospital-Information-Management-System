<?php

namespace App\Services\Directory;

use App\Services\Catalog\CatalogQueries;
use App\Services\MedicationStock\StockDirectory;
use Illuminate\Support\Facades\DB;

class IssuedCodes
{
    public function next(string $sequenceKey, string $prefix, int $width, callable $taken): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Issued codes require an enclosing transaction.');
        }
        $key = ['sequence_key' => $sequenceKey, 'scope_key' => 'global', 'period_key' => 'all'];
        DB::table('number_sequences')->insertOrIgnore($key);
        $sequence = DB::table('number_sequences')->where($key)->lockForUpdate()->first();
        $n = (int) $sequence->current_value;
        do {
            $code = $prefix.str_pad((string) ++$n, $width, '0', STR_PAD_LEFT);
        } while ($taken($code));
        DB::table('number_sequences')->where('id', $sequence->id)->update(['current_value' => $n, 'updated_at' => now()]);

        return $code;
    }

    public function visit(): string
    {
        return $this->next('visit_no', 'V-', 8, fn (string $code) => DB::table('visits')->where('visit_no', $code)->exists());
    }

    public function clinic(): string
    {
        return $this->next('clinic_code', 'AUTO-CLI-', 3, fn (string $code) => DB::table('clinics')->where('code', $code)->exists());
    }

    public function doctor(): string
    {
        return $this->next('doctor_code', 'AUTO-DR-', 3, fn (string $code) => DB::table('staff')->where('staff_code', $code)->exists());
    }

    public function catalog(string $kind): string
    {
        $prefix = match ($kind) {
            'service' => 'AUTO-SER-', 'procedure' => 'AUTO-PRO-', 'medication' => 'AUTO-MED-',
            default => throw new \InvalidArgumentException('Unknown catalog kind.'),
        };
        $table = CatalogQueries::table($kind);

        return $this->next($kind.'_code', $prefix, 3, fn (string $code) => DB::table($table)->where('code', $code)->exists());
    }

    public function classification(string $kind): string
    {
        $prefix = match ($kind) {
            'service' => 'AUTO-SCG-', 'procedure' => 'AUTO-PRT-', 'medication' => 'AUTO-MCG-',
            default => throw new \InvalidArgumentException('Unknown classification kind.'),
        };
        [$table] = CatalogQueries::classification($kind);

        return $this->next($kind.'_classification_code', $prefix, 3, fn (string $code) => DB::table($table)->where('code', $code)->exists());
    }

    public function diagnosis(): string
    {
        return $this->next('diagnosis_code', 'AUTO-DOS-DX-', 2, fn (string $code) => DB::table('diagnoses')->where('code', $code)->exists());
    }

    public function receipt(): string
    {
        return $this->next('receipt_no', 'AUTO-RCV-', 8, fn (string $code) => DB::table('medication_receipts')->where('receipt_no', $code)->exists());
    }

    public function stock(string $directory, int $facilityId): string
    {
        $prefix = $directory === 'stores' ? 'AUTO-STR-' : 'AUTO-SUP-';

        return $this->next('stock_'.$directory, $prefix, 4, fn (string $code) => DB::table(StockDirectory::table($directory))->where('facility_id', $facilityId)->where('code', $code)->exists());
    }
}
