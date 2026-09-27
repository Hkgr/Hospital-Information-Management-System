<?php

namespace App\Services\Dossiers;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;

class ReceptionAccess
{
    public function facility(User $user, int $id, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('reception.view', $entry['permissions'], true) && in_array('reception.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['today' => now($entry['facility']['timezone'])->toDateString(), 'permissions' => $entry['permissions']];
            }
        }
        abort(403, 'لا يتوفر وصول إلى الاستقبال في هذه المنشأة.');
    }

    public function patients(User $user, string $action): void
    {
        abort_unless(app(GlobalAccess::class)->allows($user, 'reception.patients.'.$action), 403, 'يتطلب دليل المرضى تفويض استقبال عالميًا صريحًا.');
    }
}
