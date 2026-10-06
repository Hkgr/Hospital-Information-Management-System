<?php

namespace App\Services\Doctors;

use App\Exceptions\DoctorException;
use App\Models\User;
use App\Services\Auth\DirectoryCreationAccess;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;

class DoctorAccess
{
    public function facility(User $user, int $id, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('doctors.view', $entry['permissions'], true)
                && in_array('doctors.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['today' => now($entry['facility']['timezone'])->toDateString(), 'permissions' => $entry['permissions']];
            }
        }
        throw new DoctorException('DOCTOR_ACCESS_DENIED', 'ليس لديك صلاحية لهذه العملية في المنشأة المحددة.', 403);
    }

    public function globalPermissions(User $user): array
    {
        return array_values(array_filter(app(GlobalAccess::class)->codes($user), fn ($code) => str_starts_with($code, 'doctors.directory.')));
    }

    public function directory(User $user, string $action, ?array $facility = null): void
    {
        if ($action === 'create' && $facility && app(DirectoryCreationAccess::class)->allows($user, $facility, 'doctors.directory.create')) {
            return;
        }
        $action = ['update' => 'edit', 'delete' => 'destroy'][$action] ?? $action;
        if (! in_array('doctors.directory.'.$action, $this->globalPermissions($user), true)) {
            throw new DoctorException('DOCTOR_DIRECTORY_ACCESS_DENIED', $action === 'create' ? 'لا تملك صلاحية إضافة طبيب في المشفى المحدد.' : 'تعديل دليل الأطباء المشترك يحتاج تفويضًا عالميًا صريحًا.', 403);
        }
    }

    public function capabilities(User $user, array $facility): array
    {
        $global = $this->globalPermissions($user);
        $tasks = [];
        foreach (['archive', 'restore', 'reactivate', 'deactivate'] as $task) {
            $tasks[$task] = in_array('doctors.directory.'.$task, $global, true);
        }

        return $tasks + ['create' => app(DirectoryCreationAccess::class)->allows($user, $facility, 'doctors.directory.create'),
            'update' => in_array('doctors.directory.edit', $global, true),
            'delete' => in_array('doctors.directory.destroy', $global, true),
            'link' => in_array('doctors.link', $facility['permissions'], true),
            'export' => in_array('doctors.export', $facility['permissions'], true),
            'view_clinics' => in_array('clinics.view', $facility['permissions'], true)];
    }
}
