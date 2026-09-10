<?php

namespace App\Services\Dashboards;

use App\Http\Responses\DashboardError;
use App\Models\User;
use App\Policies\DashboardAccessPolicy;
use App\Services\Auth\UserAccessContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class DashboardService
{
    public function __construct(private UserAccessContext $context, private DashboardAccessPolicy $policy) {}

    public function catalog(User $user, ?int $facilityId): array
    {
        $access = $this->access($user, $facilityId);
        $definitions = config('dashboards', []);
        uksort($definitions, fn ($a, $b) => (($definitions[$a]['priority'] ?? 100) <=> ($definitions[$b]['priority'] ?? 100)) ?: strcmp($a, $b));
        $dashboards = [];
        foreach ($definitions as $key => $definition) {
            if ($this->policy->allows($definition, $access, $facilityId)) {
                $dashboards[] = $this->descriptor($key, $definition, $access, $facilityId);
            }
        }

        return ['dashboards' => $dashboards, 'default_dashboard_key' => $dashboards[0]['key'] ?? null];
    }

    public function detail(User $user, string $key, ?int $facilityId): array
    {
        // Exact lookup, never config("dashboards.$key") with user-controlled dot paths.
        $definition = config('dashboards', [])[$key] ?? null;
        if (! $definition) {
            throw new HttpResponseException(DashboardError::NotFound->response());
        }
        $access = $this->access($user, $facilityId);
        if (! $this->policy->allows($definition, $access, $facilityId)) {
            throw new HttpResponseException(DashboardError::Forbidden->response());
        }
        if ($definition['access'] === 'facility_permissions' && $facilityId === null) {
            throw ValidationException::withMessages(['facility_id' => 'اختر منشأة للوصول إلى لوحة التحكم.']);
        }

        return [
            'dashboard' => $this->descriptor($key, $definition, $access, $facilityId),
            'user' => $user,
            'facilities' => array_values(array_map(fn ($entry) => $entry['facility'], $access)),
            'selected_facility_id' => $facilityId,
            // No medical/administrative links exist yet. Add only implemented, authorized links.
            'links' => [],
        ];
    }

    private function access(User $user, ?int $facilityId): array
    {
        $access = $this->context->forUser($user);
        if ($facilityId !== null) {
            $access = array_values(array_filter($access, fn ($entry) => $entry['facility']['id'] === $facilityId));
            if ($access === []) {
                throw new HttpResponseException(DashboardError::FacilityForbidden->response());
            }
        }

        return $access;
    }

    private function descriptor(string $key, array $definition, array $access, ?int $facilityId): array
    {
        $facilities = array_values(array_map(fn ($entry) => $entry['facility'], array_filter(
            $access, fn ($entry) => $this->policy->allows($definition, [$entry], $entry['facility']['id'])
        )));

        return ['key' => $key, 'title' => $definition['title'],
            'requires_facility' => $definition['access'] === 'facility_permissions',
            'facilities' => $facilities,
            'default_facility_id' => $definition['access'] === 'facility_permissions' ? ($facilityId ?? $facilities[0]['id'] ?? null) : null];
    }
}
