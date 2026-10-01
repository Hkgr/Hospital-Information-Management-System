<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\SaveRoleRequest;
use App\Http\Requests\Users\UserQueryRequest;
use App\Services\Users\ProtectedRolePolicy;
use App\Services\Users\RoleAccess;
use App\Services\Users\RoleDirectory;
use App\Services\Users\UserDirectory;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Users')]
class RoleController extends Controller
{
    public function __construct(private RoleAccess $access, private RoleDirectory $directory) {}

    /** List active roles with their permissions. Requires roles.view. User 1 is not exempt. */
    public function index(UserQueryRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->directory->listing($facility));
    }

    public function show(UserQueryRequest $request, int $role): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->directory->detail($facility, $role)]);
    }

    public function options(UserQueryRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json(app(UserDirectory::class)->options($facility));
    }

    /** Edit the protected administrator's exact permission policy, with version and reason. */
    public function protectedUpdate(SaveRoleRequest $request, int $role): JsonResponse
    {
        return response()->json(['data' => app(ProtectedRolePolicy::class)->update($request, $request->integer('facility_id'), $role, $request->validated())]);
    }

    /** Create an active role and attach grantable permissions. Requires roles.create and roles.view. Never auto-grants. */
    public function store(SaveRoleRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'create');

        return response()->json(['data' => $this->directory->create($request, $facility, $request->validated())], 201);
    }

    /** Rename a role and replace its grantable permissions. Requires roles.update and roles.view. */
    public function update(SaveRoleRequest $request, int $role): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'update');

        return response()->json(['data' => $this->directory->update($request, $facility, $role, $request->validated())]);
    }
}
