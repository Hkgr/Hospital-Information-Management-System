<?php

namespace App\Services\Dossiers;

use Illuminate\Http\Exceptions\HttpResponseException;

class OncologyIntegrity
{
    public static function reject(string $code, string $message, array $extra = [], int $status = 422): never
    {
        $errors = $extra['fields'] ?? null;
        unset($extra['fields']);
        throw new HttpResponseException(response()->json(['error' => ['code' => $code, 'message' => $message] + $extra] + ($errors ? ['errors' => $errors] : []), $status));
    }

    public static function boundaries(object $revision, array $session): void
    {
        if ($session['planned_on'] < $revision->starts_on
            || ($revision->ends_on && $session['planned_on'] > $revision->ends_on)
            || ($revision->planned_sessions && $session['session_number'] > $revision->planned_sessions)
            || ($revision->planned_cycles && ($session['cycle_number'] ?? 0) > $revision->planned_cycles)) {
            self::reject('ONCOLOGY_SESSION_OUTSIDE_PLAN', 'الموعد أو رقم الجلسة أو الدورة خارج حدود النسخة العلاجية؛ يلزم تعديل الخطة صراحة أولًا.');
        }
    }

    public static function content(array $revision, array $items): array
    {
        $fields = ['modality', 'intent', 'protocol_name', 'protocol_code', 'planned_cycles', 'planned_sessions', 'interval_days', 'starts_on', 'ends_on', 'clinic_id', 'doctor_id', 'diagnosis_id', 'diagnosis_snapshot', 'note'];
        $itemFields = ['medication_id', 'medication_name_snapshot', 'medication_code_snapshot', 'dose_value', 'dose_unit', 'route', 'instructions', 'funding_source_id', 'note'];
        $normalize = static function (array $values, array $keys): array {
            $result = [];
            foreach ($keys as $key) {
                $value = $values[$key] ?? null;
                if ($value !== null) {
                    $value = preg_replace('/\s+/u', ' ', trim((string) $value));
                    if (class_exists(\Normalizer::class)) {
                        $value = \Normalizer::normalize($value);
                    }
                    if ($key === 'dose_value') {
                        $value = rtrim(rtrim($value, '0'), '.');
                        // Integer doses must retain their significant trailing zeroes.
                        if (! str_contains((string) $values[$key], '.')) {
                            $value = (string) $values[$key];
                        }
                    }
                }
                $result[$key] = $value === '' ? null : $value;
            }

            return $result;
        };

        return [$normalize($revision, $fields), array_map(fn ($item) => $normalize((array) $item, $itemFields), array_values($items))];
    }
}
