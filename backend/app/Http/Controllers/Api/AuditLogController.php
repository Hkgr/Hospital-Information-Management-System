<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLogRequest;
use App\Services\Audit\SystemLogHistory;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;

#[Group('System log')]
class AuditLogController extends Controller
{
    public function __construct(private SystemLogHistory $history) {}

    #[Endpoint(operationId: 'systemLog', title: 'Read the facility activity log', description: 'Requires an active account, a Bearer token with the api ability, membership in the selected facility, and audit.view. User 1 does not bypass checks. Definitions are seeded and never auto-granted. facility_id is required. Optional from/to are inclusive facility-local days. category/entity/action filter the existing audit_logs table. GET never writes an audit entry. Responses are private, no-store. Values are an allowlisted projection; technical errors store a redacted message, path and method without traces or secrets.')]
    #[DocumentedResponse(200, description: 'Paginated facility activity including technical errors.')]
    public function index(AuditLogRequest $request)
    {
        $f = $this->history->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->history->listing($f, $request->validated()));
    }

    #[Endpoint(operationId: 'systemLogShow', title: 'Read one facility activity row', description: 'Requires an active account, a Bearer token with the api ability, membership in the selected facility, and audit.view. User 1 does not bypass checks. Definitions are seeded and never auto-granted. facility_id is required. The id must belong to that facility. GET never writes an audit entry. Responses are private, no-store. Values are an allowlisted projection.')]
    #[DocumentedResponse(200, description: 'One activity row including allowlisted changes.')]
    public function show(AuditLogRequest $request, int $id)
    {
        $f = $this->history->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->history->show($f, $id));
    }
}
