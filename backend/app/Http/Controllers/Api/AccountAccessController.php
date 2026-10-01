<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\UserQueryRequest;
use App\Services\Users\AccountAccessDirectory;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Users')]
class AccountAccessController extends Controller
{
    public function show(UserQueryRequest $request, int $user, AccountAccessDirectory $directory): JsonResponse
    {
        return response()->json(['data' => $directory->show($request, $request->integer('facility_id'), $user)]);
    }

    /** Explicit local assignments and/or global-only permissions; requires version and audited reason. */
    public function update(Request $request, int $user, AccountAccessDirectory $directory): JsonResponse
    {
        $input = $request->validate([
            'facility_id' => ['required', 'integer', 'min:1'], 'lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:3', 'max:255'],
            'access_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'local_role_ids' => ['sometimes', 'array', 'min:1'], 'local_role_ids.*' => ['integer', 'distinct', 'min:1'],
            'global_permission_ids' => ['sometimes', 'array'], 'global_permission_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);
        abort_unless(array_key_exists('local_role_ids', $input) || array_key_exists('global_permission_ids', $input), 422);

        return response()->json(['data' => $directory->update($request, $request->integer('facility_id'), $user, $input)]);
    }
}
