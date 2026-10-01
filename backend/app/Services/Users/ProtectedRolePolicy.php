<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\TaskPermissions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProtectedRolePolicy
{
    public const MINIMUM = ['users.view', 'roles.view', 'roles.update', 'roles.delegate', 'users.global.view', 'users.global.manage', 'users.roles.assign'];

    public static function delegates(User $user): bool
    {
        $access = app(GlobalAccess::class);

        return $access->systemRole($user) !== null && $access->allows($user, 'roles.delegate');
    }

    public static function fail(string $code, string $message, int $status = 403): never
    {
        throw new HttpResponseException(response()->json(['error' => compact('code', 'message')], $status));
    }

    public static function lockActor(Request $request, array $additionalUsers = []): void
    {
        // Rare access-administration transactions share this lock order: users,
        // role directory, permission definitions, grants, then assignments.
        // Acquire the fence before any consistent read, including under MariaDB RR.
        $users = User::whereIn('id', array_unique([$request->user()->id, ...$additionalUsers]))->orderBy('id')->lockForUpdate()->get();
        $user = $users->firstWhere('id', $request->user()->id);
        if (! $user?->is_active) {
            self::fail('ACCOUNT_INACTIVE', 'هذا الحساب غير فعال.');
        }
        DB::table('roles')->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('permissions')->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('role_permissions')->orderBy('id')->lockForUpdate()->get(['id']);
        $request->setUserResolver(fn () => $user);
    }

    public static function audit(Request $request, int $facility, string $entity, int $id, array $old, array $new, string $reason): void
    {
        $new['source'] = 'access_administration';
        DB::table('audit_logs')->insert(['facility_id' => $facility, 'actor_id' => $request->user()->id, 'entity_type' => $entity, 'entity_id' => $id, 'event' => 'updated',
            'old_values' => json_encode($old, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'new_values' => json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'reason' => $reason, 'request_id' => (string) Str::uuid(), 'ip_address' => $request->ip(), 'occurred_at' => now()]);
    }

    public function update(Request $request, int $facility, int $id, array $input): array
    {
        return DB::transaction(function () use ($request, $facility, $id, $input) {
            self::lockActor($request);
            $role = DB::table('roles')->where('id', $id)->lockForUpdate()->first();
            app(RoleAccess::class)->facility($request->user()->fresh(), $facility, 'update');
            if (! self::delegates($request->user()->fresh()) || ! $role?->is_system_super_admin || ! $role->is_active || ! $role->access_consolidated_at) {
                self::fail('PROTECTED_SYSTEM_ROLE', 'تعديل الدور المحمي يحتاج مسؤول التفويض وتهيئة الوصول المراجعة.');
            }
            if ((int) $role->lock_version !== (int) $input['lock_version']) {
                self::fail('ROLE_VERSION_CONFLICT', 'تغير الدور؛ اجلب أحدث نسخة وراجع اختياراتك.', 409);
            }
            $rows = DB::table('permissions')->whereIn('id', $input['permission_ids'])->where('is_active', true)->lockForUpdate()->get(['id', 'code']);
            $codes = $rows->pluck('code')->all();
            if ($rows->count() !== count($input['permission_ids']) || array_diff(self::MINIMUM, $codes)) {
                self::fail('PROTECTED_ACCESS_MINIMUM', 'صلاحيات إدارة الوصول المحمية ضرورية لمنع إغلاق النظام على المسؤول.', 422);
            }
            // Exact choices: umbrella permissions do not satisfy omitted granular prerequisites.
            foreach ($codes as $code) {
                $required = TaskPermissions::TASKS[$code][2] ?? [];
                if (array_diff($required, $codes)) {
                    self::fail('ROLE_PREREQUISITES_REQUIRED', 'اختر المتطلبات الناقصة صراحة قبل حفظ الدور.', 422);
                }
            }
            $old = DB::table('role_permissions')->where('role_id', $id)->pluck('permission_id')->all();
            // Retain disabled definitions and their history; only active choices are editable.
            $active = DB::table('permissions')->where('is_active', true)->pluck('id')->all();
            DB::table('role_permissions')->where('role_id', $id)->whereIn('permission_id', array_diff($active, $input['permission_ids']))->delete();
            foreach ($input['permission_ids'] as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $id, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('roles')->where('id', $id)->update(['lock_version' => $role->lock_version + 1, 'updated_at' => now()]);
            self::audit($request, $facility, 'role', $id, ['permission_ids' => $old, 'permissions' => DB::table('permissions')->whereIn('id', $old)->orderBy('code')->pluck('code')->all(), 'lock_version' => $role->lock_version], ['permission_ids' => $input['permission_ids'], 'permissions' => $codes, 'lock_version' => $role->lock_version + 1], $input['reason']);

            return app(RoleDirectory::class)->detail(app(RoleAccess::class)->facility($request->user()->fresh(), $facility), $id);
        }, 3);
    }
}
