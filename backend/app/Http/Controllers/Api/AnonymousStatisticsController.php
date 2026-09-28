<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\AuthError;
use App\Services\Auth\WebSession;
use App\Services\Clinics\ClinicAudit;
use App\Services\Reports\AnonymousStatistics;
use App\Services\Reports\AnonymousStatisticsReport;
use App\Services\Reports\FacilityReport;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class AnonymousStatisticsController extends Controller
{
    public function show(Request $r, AnonymousStatistics $statistics)
    {
        $r->validate(['facility_id' => 'required|integer|min:1']);
        $f = app(FacilityReport::class)->facility($r->user(), $r->integer('facility_id'), 'statistics.view');

        return response()->json(['data' => $statistics->assemble($f, $statistics->filters($r, $f))]);
    }

    public function export(Request $r, string $format, AnonymousStatistics $statistics, AnonymousStatisticsReport $reports)
    {
        $r->validate(['facility_id' => 'required|integer|min:1']);
        $f = app(FacilityReport::class)->facility($r->user(), $r->integer('facility_id'), 'statistics.view');
        app(FacilityReport::class)->facility($r->user(), $f['id'], 'statistics.export');
        $filters = $statistics->filters($r, $f);
        $data = $statistics->assemble($f, $filters);
        $bytes = $reports->render($data, $format, now($f['timezone'])->format('Y-m-d H:i:s'));
        // Rendering can take time. Recheck live authority before releasing bytes.
        $user = $r->user()->fresh();
        if (! $user || ! $user->is_active) {
            throw new HttpResponseException(AuthError::InactiveAccount->response());
        }
        foreach (['statistics.view', 'statistics.export'] as $permission) {
            app(FacilityReport::class)->facility($user, $f['id'], $permission);
        }
        app(WebSession::class)->check($r);
        app(ClinicAudit::class)->record($r, $f['id'], $f['id'], 'exported', null, $filters + ['format' => $format], 'anonymous_statistics');

        return response($bytes)->header('Content-Type', $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->header('Content-Disposition', 'attachment; filename="statistics.'.$format.'"')->header('X-Content-Type-Options', 'nosniff');
    }
}
