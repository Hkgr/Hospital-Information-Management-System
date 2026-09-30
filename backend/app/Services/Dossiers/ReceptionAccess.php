<?php

namespace App\Services\Dossiers;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;

class ReceptionAccess
{
    public function facility(User $user, int $id, string $action = 'view'): array
    {
        $permission = ['view' => 'patients.basic.view', 'register' => 'patient_cards.register', 'correct' => 'patients.own.correct', 'corrections.request' => 'patients.corrections.request'][$action] ?? 'reception.'.$action;
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('patients.basic.view', $entry['permissions'], true) && in_array($permission, $entry['permissions'], true)) {
                return $entry['facility'] + ['today' => now($entry['facility']['timezone'])->toDateString(), 'permissions' => $entry['permissions']];
            }
        }
        abort(403, 'لا يتوفر وصول إلى تسجيل المرضى في هذه المنشأة.');
    }

    public function patients(User $user, string $action): void
    {
        abort_unless(app(GlobalAccess::class)->allows($user, 'patients.basic.'.$action), 403, 'يتطلب دليل المرضى تفويضًا عالميًا صريحًا للعملية المطلوبة.');
    }
}
