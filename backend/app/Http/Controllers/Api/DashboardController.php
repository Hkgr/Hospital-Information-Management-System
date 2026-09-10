<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRequest;
use App\Http\Resources\Dashboards\DashboardCatalogResponse;
use App\Http\Resources\Dashboards\DashboardDetailResponse;
use App\Services\Dashboards\DashboardService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;

#[Group('Dashboards')]
class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboards) {}

    #[Endpoint(operationId: 'dashboards', title: 'List allowed dashboards', description: 'Requires an active account and a Bearer token with the api ability. Returns allowed dashboards ordered by priority then key, with a nullable default_dashboard_key. Optional facility_id restricts the catalog to an accessible active facility. Permissions must be satisfied within a single facility. General access grants no medical or administrative permissions. Responses are private, no-store.')]
    #[DocumentedResponse(200, description: 'Allowed dashboards and deterministic default; null when none are allowed.', examples: [['data' => ['dashboards' => [['key' => 'general', 'title' => 'لوحة التحكم', 'requires_facility' => false, 'facilities' => [], 'default_facility_id' => null]], 'default_dashboard_key' => 'general']]])]
    public function index(DashboardRequest $request): DashboardCatalogResponse
    {
        return new DashboardCatalogResponse($this->dashboards->catalog($request->user(), $request->facilityId()));
    }

    #[Endpoint(operationId: 'dashboardDetail', title: 'Read an allowed dashboard', description: 'Requires an active account and a Bearer token with the api ability. Authorization is checked again using current active facility/role/permission assignments on every request. Unknown keys return 404 DASHBOARD_NOT_FOUND; known but denied dashboards return 403 DASHBOARD_ACCESS_DENIED. An inaccessible facility_id returns 403 FACILITY_ACCESS_DENIED without revealing whether it exists. Restricted dashboards require facility_id (422 if omitted); privileges are never combined across facilities. General data contains only the current user and their accessible facilities. Responses are private, no-store.')]
    #[DocumentedResponse(200, description: 'Only the authenticated user and current authorized facility context.', examples: [['data' => [
        'dashboard' => ['key' => 'general', 'title' => 'لوحة التحكم', 'requires_facility' => false, 'facilities' => [], 'default_facility_id' => null],
        'user' => ['id' => 1, 'staff_id' => null, 'username' => 'example-user', 'name' => 'مستخدم توضيحي', 'email' => null, 'must_change_password' => false, 'last_login_at' => null],
        'facilities' => [], 'selected_facility_id' => null, 'links' => [],
    ]]])]
    public function show(DashboardRequest $request, string $key): DashboardDetailResponse
    {
        return new DashboardDetailResponse($this->dashboards->detail($request->user(), $key, $request->facilityId()));
    }
}
