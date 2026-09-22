<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FacilityReportRequest;
use App\Http\Resources\Reports\FacilityReportResponse;
use App\Services\Reports\FacilityReport;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Symfony\Component\HttpFoundation\Response;

#[Group('Facility reports')]
class FacilityReportController extends Controller
{
    public function __construct(private FacilityReport $reports) {}

    #[Endpoint(operationId: 'facilityReport', title: 'Read facility activity for a time window', description: 'Requires an active account, a Bearer token with the api ability, and membership in the selected facility. No extra permission code is assigned. User 1 does not bypass checks. facility_id and period are required. period=day is today in the facility timezone; period=week is the last 7 inclusive days ending today; period=custom requires from and to (inclusive, at most 366 days) and prohibits omitting either date. Stats, ranks and the patients table are omitted when the matching facility permission is absent; privileges are never combined across facilities. GET never writes an audit row. Responses are private, no-store.')]
    #[DocumentedResponse(200, description: 'Permission-gated activity for the resolved window.')]
    public function index(FacilityReportRequest $request): FacilityReportResponse
    {
        $f = $this->reports->facility($request->user(), $request->integer('facility_id'));

        return new FacilityReportResponse($this->reports->assemble($f, $request->validated()));
    }

    #[Endpoint(operationId: 'facilityReportPdf', title: 'Export the facility activity snapshot as PDF', description: 'Requires an active account, a Bearer token with the api ability, and membership in the selected facility. No extra permission code is assigned. The same period window as the JSON snapshot applies. The PDF contains only permission-gated figures. Export writes one audit row with entity facility_report. Responses are private, no-store.')]
    #[DocumentedResponse(200, description: 'PDF snapshot of the current window.')]
    public function export(FacilityReportRequest $request): Response
    {
        $f = $this->reports->facility($request->user(), $request->integer('facility_id'));

        return $this->reports->pdf($request, $f, $request->validated());
    }
}
