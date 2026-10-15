<?php

namespace App\Services\Dossiers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicationSources
{
    public function choices(): Collection
    {
        return DB::table('funding_sources')->where('is_active', true)->orderBy('display_order')->orderBy('id')->get(['id', 'code', 'name_ar']);
    }

    public function labels(): array
    {
        return DB::table('funding_sources')->pluck('name_ar', 'code')->all();
    }

    public function selected(int|string $value, string $column, int|string|null $retained, string $field): object
    {
        $row = DB::table('funding_sources')->where($column, $value)->lockForUpdate()->first();
        if (! $row || (! $row->is_active && (string) $retained !== (string) $value) || ($column === 'code' && $row->code !== $value && $retained !== $value)) {
            throw ValidationException::withMessages([$field => 'اختر مصدرًا فعالًا من مصدر الدواء العام.']);
        }

        return $row;
    }

    // Initial reference data only. Runtime choices and labels always come from the existing table.
    public function bootstrap(bool $apply): array
    {
        return DB::transaction(function () use ($apply) {
            $approved = json_decode(file_get_contents(database_path('data/medication-sources.json')), true, flags: JSON_THROW_ON_ERROR);
            $existing = DB::table('funding_sources')->orderBy('id')->lockForUpdate()->get();
            $plan = [];
            foreach ($approved as $source) {
                $matches = $existing->filter(fn ($s) => mb_strtolower(trim($s->code)) === $source['code'] || trim($s->name_ar) === $source['name_ar']);
                if ($matches->count() > 1 || ($matches->count() === 1 && $matches->first()->code !== $source['code'])) {
                    throw new \RuntimeException('Ambiguous funding source '.$source['code'].' ['.$matches->map(fn ($s) => $s->id.':'.$s->code)->implode(', ').']: no name-based mapping is applied.');
                }
                $old = $matches->first();
                $plan[] = [$old ? ($old->is_active ? 'KEEP' : 'KEEP DISABLED') : 'ADD', $source['code'], $old?->name_ar ?? $source['name_ar'], $old?->id];
            }
            if ($apply) {
                foreach ($plan as $order => [$action, $code, $name]) {
                    if ($action === 'ADD') {
                        DB::table('funding_sources')->insert(['code' => $code, 'name_ar' => $name, 'display_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }

            return $plan;
        }, 3);
    }
}
