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

    public static function content(array $revision): array
    {
        $fields = ['modality', 'intent', 'protocol_text', 'protocol_clinic_id', 'protocol_doctor_id', 'treating_clinic_id', 'treating_doctor_id'];
        $result = [];
        foreach ($fields as $key) {
            $value = $revision[$key] ?? null;
            if ($value !== null) {
                $value = preg_replace('/\s+/u', ' ', trim((string) $value));
                if (class_exists(\Normalizer::class)) {
                    $value = \Normalizer::normalize($value);
                }
            }
            $result[$key] = $value === '' ? null : $value;
        }

        return $result;
    }
}
