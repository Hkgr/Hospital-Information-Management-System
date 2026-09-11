<?php

namespace App\Services\Clinics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClinicAudit
{
    public function record(Request $request, int $facilityId, int $clinicId, string $event, ?array $old, ?array $new): void
    {
        DB::table('audit_logs')->insert([
            'facility_id' => $facilityId, 'actor_id' => $request->user()->id,
            'entity_type' => 'clinic', 'entity_id' => $clinicId, 'event' => $event,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'request_id' => (string) Str::uuid(), 'ip_address' => $request->ip(), 'occurred_at' => now(),
        ]);
    }
}
