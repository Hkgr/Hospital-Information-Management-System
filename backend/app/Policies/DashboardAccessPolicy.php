<?php

namespace App\Policies;

class DashboardAccessPolicy
{
    /** Access entries must come from UserAccessContext for the current request. */
    public function allows(array $definition, array $access, ?int $facilityId = null): bool
    {
        if (($definition['access'] ?? null) === 'authenticated') {
            return $facilityId === null || collect($access)->contains('facility.id', $facilityId);
        }
        // Fail closed: a restricted dashboard must explicitly require permissions.
        $permissions = $definition['permissions'] ?? [];
        if (($definition['access'] ?? null) !== 'facility_permissions' || ! is_array($permissions) || $permissions === []) {
            return false;
        }
        foreach ($access as $entry) {
            if (($facilityId === null || $entry['facility']['id'] === $facilityId)
                && array_diff($permissions, $entry['permissions']) === []) {
                return true;
            }
        }

        return false;
    }
}
