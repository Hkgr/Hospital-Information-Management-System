<?php

namespace App\Services\Auth;

use App\Exceptions\AccessException;
use App\Models\User;

class PermissionGate
{
    public function __construct(private UserAccessContext $access) {}

    /**
     * @return array{id: int, code: string, name_ar: string, timezone: string, today: string}
     */
    public function facility(User $user, int $facilityId, string $resource, string $action = 'view'): array
    {
        foreach ($this->access->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $facilityId
                && in_array($resource.'.view', $entry['permissions'], true)
                && in_array($resource.'.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }

        throw new AccessException('ACCESS_DENIED', 'ليس لديك صلاحية لهذه العملية في المنشأة المحددة.', 403);
    }

    public function global(User $user, string $permission): void
    {
        if (! in_array($permission, $this->access->globalForUser($user)['permissions'], true)) {
            throw new AccessException('ACCESS_DENIED', 'هذه العملية تحتاج تفويضًا عالميًا صريحًا.', 403);
        }
    }
}
