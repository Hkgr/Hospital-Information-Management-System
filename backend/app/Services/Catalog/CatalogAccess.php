<?php

namespace App\Services\Catalog;

use App\Exceptions\CatalogException;
use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Illuminate\Support\Facades\DB;

class CatalogAccess
{
    public function context(User $user): array
    {
        $code = config('catalog.facility_code');
        if (! is_string($code) || $code === '') {
            throw new CatalogException('CATALOG_FACILITY_UNCONFIGURED', 'لم يُحدد مشفى بن زايد لهذا القسم. راجع مسؤول النظام.', 422);
        }
        $id = DB::table('facilities')->where('code', $code)->where('is_active', true)->value('id');
        if (! $id) {
            throw new CatalogException('CATALOG_ACCESS_DENIED', 'المشفى المحدد غير متاح أو غير فعال.', 403);
        }
        $facility = $this->facility($user, (int) $id);

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

    public function capabilities(User $user, array $facility): array
    {
        $global = DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('g.user_id', $user->id)->where('r.is_active', true)->where('p.is_active', true)->pluck('p.code')->all();

        return ['create' => in_array('catalog.directory.create', $global, true), 'update' => in_array('catalog.directory.update', $global, true),
            'delete' => in_array('catalog.directory.delete', $global, true), 'export' => in_array('catalog.export', $facility['permissions'], true),
            'beneficiaries' => in_array('catalog.beneficiaries', $facility['permissions'], true), 'audit' => in_array('catalog.audit', $facility['permissions'], true)];
    }

    public function directory(User $user, array $facility, string $action): void
    {
        if (! $this->capabilities($user, $facility)[$action]) {
            throw new CatalogException('CATALOG_DIRECTORY_ACCESS_DENIED', 'تغيير الدليل المشترك يحتاج تفويضًا عالميًا صريحًا.', 403);
        }
    }
}
