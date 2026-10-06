<?php

namespace App\Services\Catalog;

use App\Exceptions\CatalogException;
use App\Models\User;
use App\Services\Auth\DirectoryCreationAccess;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;

class CatalogAccess
{
    public function context(User $user, int $id): array
    {
        $facility = $this->facility($user, $id);

        return array_intersect_key($facility, array_flip(['id', 'code', 'name_ar', 'timezone']));
    }

    public function facility(User $user, int $id, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('catalog.view', $entry['permissions'], true)
                && in_array('catalog.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['permissions' => $entry['permissions'], 'today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }
        throw new CatalogException('CATALOG_ACCESS_DENIED', 'ليس لديك صلاحية لهذه العملية في المنشأة المحددة.', 403);
    }

    public function capabilities(User $user, array $facility, string $kind = 'service'): array
    {
        $global = app(GlobalAccess::class)->codes($user);

        $tasks = [];
        foreach (['archive', 'destroy', 'deactivate', 'restore', 'reactivate'] as $task) {
            $tasks[$task] = in_array('catalog.directory.'.$task, $global, true);
        }

        return $tasks + ['create' => app(DirectoryCreationAccess::class)->allows($user, $facility, $kind === 'medication' ? 'medications.create' : 'catalog.directory.create'), 'update' => in_array('catalog.directory.edit', $global, true),
            'delete' => $tasks['destroy'], 'export' => in_array('catalog.export', $facility['permissions'], true),
            'beneficiaries' => in_array('catalog.beneficiaries', $facility['permissions'], true), 'audit' => in_array('catalog.audit', $facility['permissions'], true)];
    }

    public function directory(User $user, array $facility, string $action, string $kind = 'service'): void
    {
        if (! $this->capabilities($user, $facility, $kind)[$action]) {
            throw new CatalogException('CATALOG_DIRECTORY_ACCESS_DENIED', $action === 'create' ? 'لا تملك صلاحية إضافة هذا النوع في المشفى المحدد.' : 'تغيير الدليل المشترك يحتاج تفويضًا عالميًا صريحًا.', 403);
        }
    }
}
