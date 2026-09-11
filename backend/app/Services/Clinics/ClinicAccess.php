<?php

namespace App\Services\Clinics;

use App\Exceptions\ClinicException;
use App\Models\User;
use App\Services\Auth\UserAccessContext;

class ClinicAccess
{
    public function authorize(User $user, int $facilityId, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $facilityId
                && in_array('clinics.view', $entry['permissions'], true)
                && in_array('clinics.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }
        throw new ClinicException('CLINIC_ACCESS_DENIED', 'ليس لديك صلاحية لهذه العملية في المنشأة المحددة.', 403);
    }
}
