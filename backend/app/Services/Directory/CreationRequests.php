<?php

namespace App\Services\Directory;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Call only after current facility and operation permissions have been checked. */
class CreationRequests
{
    public function save(Request $request, array $facility, array $data, string $operation, ?int $id, callable $write): int
    {
        if ($id !== null) {
            return DB::transaction($write, 3);
        }
        $fingerprint = hash('sha256', $operation.json_encode($this->canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $facility, $data, $fingerprint, $write) {
            $key = ['facility_id' => $facility['id'], 'user_id' => $request->user()->id, 'request_id' => strtolower($data['request_id'])];
            // The unique key serializes concurrent first attempts before issuing a code.
            DB::table('directory_creation_requests')->insertOrIgnore($key + ['fingerprint' => $fingerprint, 'created_at' => now()]);
            $entry = DB::table('directory_creation_requests')->where($key)->lockForUpdate()->first();
            if ($entry->fingerprint !== $fingerprint) {
                throw new HttpResponseException(response()->json(['error' => [
                    'code' => 'CREATION_REQUEST_CONFLICT',
                    'message' => 'استُخدم معرّف الطلب لمحتوى مختلف. استعد نتيجة الحفظ السابق قبل إنشاء طلب آخر.',
                ]], 409));
            }
            if ($entry->entity_id !== null) {
                return (int) $entry->entity_id;
            }
            $id = $write();
            DB::table('directory_creation_requests')->where('id', $entry->id)->update(['entity_id' => $id]);

            return $id;
        }, 3);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }
}
