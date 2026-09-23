<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\Catalog\CatalogQueries;
use App\Services\Clinics\ClinicAudit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserDirectory
{
    public function listing(array $f, array $filters): array
    {
        $q = DB::table('users as u')
            ->join('facility_user_roles as a', 'a.user_id', '=', 'u.id')
            ->where('a.facility_id', $f['id']);
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $q->where(fn ($q) => $q->where('u.username', 'like', $term)->orWhere('u.name', 'like', $term));
        }
        $page = (clone $q)->select('u.id')->groupBy('u.id')->orderBy('u.username')->orderBy('u.id')
            ->paginate($filters['per_page'] ?? 20, ['u.id'], 'page', $filters['page'] ?? 1);
        $ids = $page->getCollection()->pluck('id');
        $rows = $ids->isEmpty() ? collect() : DB::table('users as u')
            ->join('facility_user_roles as a', 'a.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.facility_id', $f['id'])->whereIn('u.id', $ids)
            ->orderBy('u.username')->orderBy('r.code')
            ->get(['u.id', 'u.username', 'u.name', 'u.email', 'u.is_active', 'u.last_login_at', 'r.id as role_id', 'r.code as role_code', 'r.name_ar as role_name']);
        $data = [];
        foreach ($rows as $row) {
            $data[$row->id] ??= [
                'id' => (int) $row->id, 'username' => $row->username, 'name' => $row->name, 'email' => $row->email,
                'is_active' => (bool) $row->is_active, 'last_login_at' => $row->last_login_at, 'roles' => [],
            ];
            $data[$row->id]['roles'][] = ['id' => (int) $row->role_id, 'code' => $row->role_code, 'name_ar' => $row->role_name];
        }

        return ['data' => array_values($data), 'meta' => CatalogQueries::meta($page)];
    }

    public function options(array $f): array
    {
        $roles = app(RoleDirectory::class)->assignable($f);

        return ['data' => ['roles' => $roles, 'permission_groups' => app(PermissionCatalog::class)->grouped($f['permissions']), 'capabilities' => [
            'view' => in_array('users.view', $f['permissions'], true),
            'create' => in_array('users.create', $f['permissions'], true),
            'delete' => in_array('users.delete', $f['permissions'], true),
            'roles_view' => in_array('roles.view', $f['permissions'], true),
            'roles_create' => in_array('roles.create', $f['permissions'], true),
            'roles_update' => in_array('roles.update', $f['permissions'], true),
        ]]];
    }

    public function create(Request $request, array $f, array $input): array
    {
        return DB::transaction(function () use ($request, $f, $input) {
            $role = app(RoleDirectory::class)->assertAssignable($f, (int) $input['role_id']);
            if (User::where('username', $input['username'])->lockForUpdate()->exists()) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'USER_USERNAME_TAKEN', 'message' => 'اسم المستخدم مستخدم مسبقاً.']], 422));
            }
            $email = $input['email'] ?? null;
            if ($email && User::where('email', $email)->lockForUpdate()->exists()) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'USER_EMAIL_TAKEN', 'message' => 'البريد الإلكتروني مستخدم مسبقاً.']], 422));
            }
            $user = User::create([
                'username' => $input['username'],
                'name' => $input['name'],
                'email' => $email,
                'password' => $input['password'],
            ]);
            DB::table('facility_user_roles')->insert([
                'facility_id' => $f['id'], 'user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(ClinicAudit::class)->record($request, $f['id'], $user->id, 'created', null, ['username' => $user->username, 'role' => $role->code], 'auth_session');

            return $this->present($f, $user->id);
        });
    }

    public function destroy(Request $request, array $f, int $id): void
    {
        DB::transaction(function () use ($request, $f, $id) {
            $user = User::where('id', $id)->lockForUpdate()->first();
            if (! $user || ! DB::table('facility_user_roles')->where('facility_id', $f['id'])->where('user_id', $id)->exists()) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'USER_NOT_FOUND', 'message' => 'المستخدم غير موجود في المنشأة المحددة.']], 404));
            }
            if ($id === (int) $request->user()->id) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'USER_SELF_DELETE', 'message' => 'لا يمكن حذف حساب الدخول الحالي.']], 409));
            }
            $username = $user->username;
            DB::table('facility_user_roles')->where('facility_id', $f['id'])->where('user_id', $id)->delete();
            if (! DB::table('facility_user_roles')->where('user_id', $id)->exists()) {
                DB::table('global_user_roles')->where('user_id', $id)->delete();
                $user->tokens()->delete();
                $user->is_active = false;
                $user->save();
                $referenced = DB::table('audit_logs')->where('actor_id', $id)->exists()
                    || DB::table('staff_work_days')->where('entered_by', $id)->exists()
                    || DB::table('facility_settings')->where('correction_user_id', $id)->exists()
                    || DB::table('patient_dossiers')->where(fn ($q) => $q->where('entered_by', $id)->orWhere('updated_by', $id))->exists();
                if (! $referenced) {
                    $user->delete();
                }
            }
            app(ClinicAudit::class)->record($request, $f['id'], $id, 'deleted', ['username' => $username], null, 'auth_session');
        });
    }

    private function present(array $f, int $id): array
    {
        $rows = DB::table('users as u')
            ->join('facility_user_roles as a', 'a.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.facility_id', $f['id'])->where('u.id', $id)
            ->orderBy('r.code')
            ->get(['u.id', 'u.username', 'u.name', 'u.email', 'u.is_active', 'u.last_login_at', 'r.id as role_id', 'r.code as role_code', 'r.name_ar as role_name']);
        if ($rows->isEmpty()) {
            $user = User::findOrFail($id);

            return ['id' => $user->id, 'username' => $user->username, 'name' => $user->name, 'email' => $user->email, 'is_active' => $user->is_active, 'last_login_at' => $user->last_login_at, 'roles' => []];
        }
        $first = $rows->first();

        return [
            'id' => (int) $first->id, 'username' => $first->username, 'name' => $first->name, 'email' => $first->email,
            'is_active' => (bool) $first->is_active, 'last_login_at' => $first->last_login_at,
            'roles' => $rows->map(fn ($row) => ['id' => (int) $row->role_id, 'code' => $row->role_code, 'name_ar' => $row->role_name])->values()->all(),
        ];
    }
}
