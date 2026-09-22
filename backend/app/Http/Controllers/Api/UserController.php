<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\SaveUserRequest;
use App\Http\Requests\Users\UserQueryRequest;
use App\Services\Users\UserAccess;
use App\Services\Users\UserDirectory;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

#[Group('Users')]
class UserController extends Controller
{
    public function __construct(private UserAccess $access, private UserDirectory $directory) {}

    /** List facility members. Requires users.view. Passwords are never returned. */
    public function index(UserQueryRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->directory->listing($facility, $request->validated()));
    }

    /** Active roles and scoped capabilities. Requires users.view. */
    public function options(UserQueryRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->directory->options($facility));
    }

    /** Create a user and attach one active role in the facility. Requires users.create and users.view. */
    public function store(SaveUserRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'create');

        return response()->json(['data' => $this->directory->create($request, $facility, $request->validated())], 201);
    }

    /** Unlink a user from the facility. Last membership deactivates the account. Requires users.delete and users.view. */
    public function destroy(UserQueryRequest $request, int $user): Response
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'delete');
        $this->directory->destroy($request, $facility, $user);

        return response()->noContent();
    }
}
