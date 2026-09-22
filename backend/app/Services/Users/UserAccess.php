<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Illuminate\Http\Exceptions\HttpResponseException;

class UserAccess
{
    public function facility(User $user, int $id, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id
                && in_array('users.view', $entry['permissions'], true)
                && in_array('users.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['permissions' => $entry['permissions']];
            }
        }
        throw new HttpResponseException(response()->json(['error' => ['code' => 'USERS_ACCESS_DENIED', 'message' => 'لا يتوفر لك وصول إلى إدارة المستخدمين في هذه المنشأة.']], 403));
    }
}
