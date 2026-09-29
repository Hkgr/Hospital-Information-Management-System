<?php

namespace App\Services\Dossiers;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcedureLocation
{
    public function check(array $f, int $clinic, ?string $location, string $field): void
    {
        if ($location === null) {
            return;
        }
        $q = DB::table('clinics')->where('id', $clinic)->where('facility_id', $f['id']);
        $valid = $location === 'radiology'
            ? $q->where('care_setting', 'radiology')->exists()
            : $q->where(fn ($q) => $q->where('clinic_kind', 'surgical')->orWhere('care_setting', 'surgical'))->exists();
        if (! $valid) {
            throw ValidationException::withMessages([$field => $location === 'radiology'
                ? 'الخزعة الموجهة تُنفذ في قسم الأشعة فقط؛ اختر قسم الأشعة، وليس عيادة.'
                : 'هذا الإجراء يُنفذ في عيادة جراحية؛ راجع مكان التنفيذ.']);
        }
    }

    public function catalog(array $f, int $clinic, int $procedure, string $field): array
    {
        $row = DB::table('procedures')->where('id', $procedure)->first();
        abort_unless($row, 404);
        $this->check($f, $clinic, $row->execution_location, $field);

        return ['execution_location_snapshot' => $row->execution_location, 'guidance_method_snapshot' => $row->guidance_method];
    }
}
