<?php

namespace App\Services\Dossiers;

use App\Services\Clinics\ClinicAudit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DossierWrites
{
    public static function conflict(string $message = 'تغيّرت البيانات. مسودتك محفوظة؛ اجلب أحدث نسخة وراجع الاختيارات.'): never
    {
        throw new HttpResponseException(response()->json(['error' => ['code' => 'DOSSIER_VERSION_CONFLICT', 'message' => $message]], 409));
    }

    public static function version(array $row, int $version): void
    {
        if ((int) $row['lock_version'] !== $version) {
            self::conflict();
        }
    }

    public function once(Request $r, array $f, array $data, string $operation, callable $write): int
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };
        $fingerprint = hash('sha256', $operation.json_encode($canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($r, $f, $data, $fingerprint, $write) {
            $key = ['facility_id' => $f['id'], 'user_id' => $r->user()->id, 'request_id' => $data['request_id']];
            DB::table('dossier_requests')->insertOrIgnore($key + ['fingerprint' => $fingerprint, 'created_at' => now()]);
            $entry = DB::table('dossier_requests')->where($key)->lockForUpdate()->first();
            if ($entry->fingerprint !== $fingerprint) {
                self::conflict('استُخدم معرّف الحفظ لمحتوى مختلف. راجع المسودة وأعد الحفظ بطلب جديد.');
            }
            if ($entry->entity_id) {
                return (int) $entry->entity_id;
            }
            $id = $write();
            DB::table('dossier_requests')->where('id', $entry->id)->update(['entity_id' => $id]);

            return $id;
        }, 3);
    }

    public function dossier(array $f, int $id, bool $lock = true): array
    {
        $q = DB::table('patient_dossiers')->where('id', $id)->where('facility_id', $f['id'])->whereIn('status', ['draft', 'active']);
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($row, 404);

        return (array) $row;
    }

    public function progress(Request $r, array $f, int $id, string $section, string $state = 'saved', ?int $visit = null): void
    {
        // The dossier row is locked by every writer before updating section progress.
        $key = ['dossier_id' => $id, 'facility_id' => $f['id'], 'section' => $section];
        $prior = DB::table('dossier_section_progress')->where($key)->first();
        DB::table('dossier_section_progress')->updateOrInsert($key, ['state' => $state, 'last_saved_by' => $r->user()->id, 'last_saved_at' => now(), 'lock_version' => ($prior?->lock_version ?? 0) + 1, 'visit_id' => $visit]);
    }

    public function audit(Request $r, array $f, string $type, int $id, ?array $old, array $new, string $event = 'saved'): void
    {
        app(ClinicAudit::class)->record($r, $f['id'], $id, $event, $old, $new, $type);
    }
}
