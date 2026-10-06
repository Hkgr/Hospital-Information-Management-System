<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

class DirectoryCreationAccess
{
    public function allows(User $user, array $facility, string $permission): bool
    {
        return in_array($permission, TaskPermissions::ROLE_CREATION, true)
            && (in_array($permission, $facility['permissions'], true)
                || app(GlobalAccess::class)->allows($user, $permission));
    }

    /** Creation returns only the new directory definition, never medical records. */
    public function facility(User $user, int $id, string $permission): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id) {
                $facility = $entry['facility'] + ['permissions' => $entry['permissions']];
                if ($this->allows($user, $facility, $permission)) {
                    return $facility;
                }
            }
        }
        throw new HttpResponseException(response()->json(['error' => ['code' => 'DIRECTORY_CREATE_DENIED', 'message' => 'لا تملك صلاحية إضافة هذا النوع في المشفى المحدد.']], 403));
    }
}
