<?php

namespace App\Services\Doctors;

use App\Exceptions\DoctorException;
use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Illuminate\Support\Facades\DB;

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
        return DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('g.user_id', $user->id)->where('r.is_active', true)->where('p.is_active', true)
            ->whereIn('p.code', ['doctors.directory.create', 'doctors.directory.update', 'doctors.directory.delete'])
            ->distinct()->orderBy('p.code')->pluck('p.code')->all();
    }

    public function directory(User $user, string $action): void
    {
        if (! in_array('doctors.directory.'.$action, $this->globalPermissions($user), true)) {
            throw new DoctorException('DOCTOR_DIRECTORY_ACCESS_DENIED', 'تعديل دليل الأطباء المشترك يحتاج تفويضًا عالميًا صريحًا.', 403);
        }
    }

    public function capabilities(User $user, array $facility): array
    {
        $global = $this->globalPermissions($user);

        return ['create' => in_array('doctors.directory.create', $global, true),
            'update' => in_array('doctors.directory.update', $global, true),
            'delete' => in_array('doctors.directory.delete', $global, true),
            'link' => in_array('doctors.link', $facility['permissions'], true),
            'export' => in_array('doctors.export', $facility['permissions'], true),
            'view_clinics' => in_array('clinics.view', $facility['permissions'], true)];
    }
}
