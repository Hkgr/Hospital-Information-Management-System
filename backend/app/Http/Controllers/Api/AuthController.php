<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Auth\CurrentUserResponse;
use App\Http\Resources\Auth\LoginResponse;
use App\Http\Responses\AuthError;
use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

#[Group('Authentication')]
class AuthController extends Controller
{
    public function __construct(private UserAccessContext $access) {}

    /**
     * Authenticate by username only (not email). Tokens have the api ability;
     * roles and permissions are resolved from the database on each request.
     * Existing device tokens remain valid. The plain-text token is returned only here.
     */
    #[Endpoint(operationId: 'login', title: 'Log in with username and password', description: 'Authenticate by username only, not email. Returns a Sanctum token once; existing device tokens remain valid. The default device_name is hospital-web. Access is resolved dynamically from active facilities, roles and permissions.')]
    #[DocumentedResponse(200, description: 'Authenticated. Store the token securely; it is returned only when created.', examples: [[
        'data' => [
            'token' => '1|example-plain-text-token', 'token_type' => 'Bearer', 'expires_at' => null,
            'user' => ['id' => 1, 'staff_id' => 10, 'username' => 'admin', 'name' => 'اسم المستخدم',
                'email' => null, 'must_change_password' => true, 'last_login_at' => '2026-09-10T12:00:00Z'],
            'access' => [[
                'facility' => ['id' => 1, 'code' => 'MBZ-ALEPPO', 'name_ar' => 'المشفى الإماراتي - حلب', 'timezone' => 'Asia/Damascus'],
                'roles' => [['code' => 'ADMIN', 'name_ar' => 'مدير النظام', 'name_en' => 'Administrator']],
                'permissions' => ['patients.create', 'patients.view'],
            ]],
        ],
    ]])]
    public function login(LoginRequest $request): LoginResponse
    {
        $input = $request->validated();

        return DB::transaction(function () use ($input) {
            $user = User::where('username', $input['username'])->lockForUpdate()->first();

            // A dummy hash check also consumes password-hashing work for unknown users.
            $hash = $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
            if (! Hash::check($input['password'], $hash) || ! $user) {
                throw new HttpResponseException(AuthError::InvalidCredentials->response());
            }
            if (! $user->is_active) {
                throw new HttpResponseException(AuthError::InactiveAccount->response());
            }

            if (Hash::needsRehash($user->password)) {
                $user->password = Hash::make($input['password']);
            }
            $token = $user->createToken($input['device_name'] ?? 'hospital-web', ['api']);
            $user->last_login_at = now();
            $user->save();

            return new LoginResponse([
                'token' => $token, 'user' => $user, 'access' => $this->access->forUser($user),
            ]);
        });
    }

    /** Return the current user and active facility access, without a token or credentials. */
    #[Endpoint(operationId: 'currentUser', title: 'Get the current user and access', description: 'Requires a Bearer token. Returns the user and current active facility access without a token, password or remember_token.')]
    #[DocumentedResponse(200, description: 'Current user and active access.', examples: [[
        'data' => [
            'user' => ['id' => 1, 'staff_id' => null, 'username' => 'admin', 'name' => 'اسم المستخدم',
                'email' => null, 'must_change_password' => false, 'last_login_at' => '2026-09-10T12:00:00Z'],
            'access' => [],
        ],
    ]])]
    public function currentUser(Request $request): CurrentUserResponse
    {
        return new CurrentUserResponse([
            'user' => $request->user(), 'access' => $this->access->forUser($request->user()),
        ]);
    }

    /** Revoke only the Bearer token used for this request. Other device tokens remain valid. */
    #[Endpoint(operationId: 'logout', title: 'Log out the current device', description: 'Requires a Bearer token. Revokes only the current token; other devices remain signed in. Returns 204 without a response body.')]
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
