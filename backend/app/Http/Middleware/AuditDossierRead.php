<?php

namespace App\Http\Middleware;

use App\Services\Clinics\ClinicAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditDossierRead
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $id = $request->route('dossier');
        // Covers clinical details, wizard/progress/new-visit reads and all nested
        // detail tabs. Listing/search and the audit log itself are not a card open.
        if ($request->isMethod('GET') && $id && $response->isSuccessful() && ! str_ends_with($request->path(), '/audit')) {
            $facility = $request->integer('facility_id');
            if (DB::table('patient_dossiers')->where('id', $id)->where('facility_id', $facility)->exists()) {
                app(ClinicAudit::class)->record($request, $facility, (int) $id, 'opened', null, [
                    'surface' => $request->is('api/reception/*') ? 'reception' : 'medical',
                    'visit_id' => $request->route('visit') ? (int) $request->route('visit') : null,
                ], 'patient_dossier');
            }
        }

        return $response;
    }
}
