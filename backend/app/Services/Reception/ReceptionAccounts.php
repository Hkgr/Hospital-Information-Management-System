<?php

namespace App\Services\Reception;

use App\Models\User;
use App\Services\Dossiers\DossierWrites;
use Database\Seeders\PermissionMatrixPhaseTwoSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReceptionAccounts
{
    private function memberships(int $user)
    {
        return DB::table('facility_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.user_id', $user)->get(['a.*', 'r.code', 'r.is_system_super_admin']);
    }

    public function blockers(Request $r, array $f, User $user): array
    {
        $memberships = $this->memberships($user->id);
        abort_unless($memberships->contains('facility_id', $f['id']), 404);
        $reasons = [];
        if ($user->id === 1 || $user->id === $r->user()->id || $memberships->contains('code', 'super_admin') || $memberships->contains('is_system_super_admin', 1)
            || DB::table('global_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.user_id', $user->id)->where(fn ($q) => $q->where('r.is_system_super_admin', true)->orWhere('r.code', 'super_admin'))->exists()) {
            $reasons[] = 'الحساب محمي أو هو حسابك الحالي.';
        }
        if ($memberships->contains(fn ($m) => (int) $m->facility_id !== $f['id'])) {
            $reasons[] = 'الحساب مستخدم في منشأة أخرى؛ التجميد العام يحتاج مراجعة مستقلة.';
        }
        $codes = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->whereIn('rp.role_id', $memberships->pluck('role_id'))->pluck('p.code')->all();
        $allowed = [...PermissionMatrixPhaseTwoSeeder::CLERK, 'reception.patients.search', 'reception.patients.create'];
        if (array_diff($codes, $allowed)) {
            $reasons[] = 'الحساب ليس حساب استقبال محدودًا.';
        }
        $global = DB::table('global_user_roles as a')->join('role_permissions as rp', 'rp.role_id', '=', 'a.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('a.user_id', $user->id)->pluck('p.code')->all();
        if (array_diff($global, $allowed)) {
            $reasons[] = 'للحساب تفويضات عالمية مستقلة لا تديرها هذه الشاشة.';
        }
        if (array_diff(array_intersect($codes, PermissionMatrixPhaseTwoSeeder::CLERK), $f['permissions'])) {
            $reasons[] = 'صلاحيات الحساب تتجاوز صلاحياتك.';
        }

        return $reasons;
    }

    public function listing(Request $r, array $f): array
    {
        $query = User::whereIn('id', DB::table('facility_user_roles')->where('facility_id', $f['id'])->select('user_id'))->orderBy('id');
        $page = $query->paginate(20);

        return ['rows' => $page->getCollection()->map(fn ($u) => $this->present($r, $f, $u)), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'grantable' => array_values(array_intersect(PermissionMatrixPhaseTwoSeeder::CLERK, $f['permissions']))];
    }

    private function present(Request $r, array $f, User $user): array
    {
        $roles = DB::table('facility_user_roles')->where('facility_id', $f['id'])->where('user_id', $user->id)->pluck('role_id');
        $permissions = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->whereIn('rp.role_id', $roles)->where('p.is_active', true)->distinct()->pluck('p.code')->all();

        return ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'is_active' => $user->is_active, 'lock_version' => $user->lock_version,
            'permissions' => array_values(array_intersect($permissions, PermissionMatrixPhaseTwoSeeder::CLERK)), 'blockers' => $this->blockers($r, $f, $user)];
    }

    public function update(Request $r, array $f, int $id): int
    {
        $input = $r->validate(['request_id' => 'required|uuid', 'lock_version' => 'required|integer|min:1', 'is_active' => 'required|boolean', 'reason' => 'required|string|max:255', 'permissions' => 'present|array|max:4', 'permissions.*' => ['string', 'distinct', Rule::in(PermissionMatrixPhaseTwoSeeder::CLERK)]]);
        abort_if(array_diff($input['permissions'], $f['permissions']), 403);

        return app(DossierWrites::class)->once($r, $f, $input, 'reception:account:'.$id, function () use ($r, $f, $id, $input) {
            $f = app(ReviewAccess::class)->facility($r, 'reception_accounts.manage');
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            $blockers = $this->blockers($r, $f, $user);
            abort_if($blockers !== [], 403, implode(' ', $blockers));
            abort_if(array_diff($input['permissions'], $f['permissions']), 403);
            if ((int) $user->lock_version !== $input['lock_version']) {
                ReviewAccess::conflict('تغيّر الحساب. اجلب أحدث نسخة قبل تأكيد القرار.');
            }
            $old = $this->present($r, $f, $user);
            $code = 'reception-'.$f['id'].'-'.$id;
            DB::table('roles')->insertOrIgnore(['code' => $code, 'name_ar' => 'صلاحيات استقبال فردية', 'is_active' => true]);
            $role = DB::table('roles')->where('code', $code)->lockForUpdate()->first();
            abort_if($role->is_system_super_admin, 403);
            abort_if(DB::table('facility_user_roles')->where('role_id', $role->id)->where(fn ($q) => $q->where('user_id', '!=', $id)->orWhere('facility_id', '!=', $f['id']))->exists()
                || DB::table('global_user_roles')->where('role_id', $role->id)->exists(), 403, 'الدور الفردي مرتبط بسياق آخر؛ يلزم مراجعة مستقلة.');
            DB::table('role_permissions')->where('role_id', $role->id)->delete();
            foreach (DB::table('permissions')->whereIn('code', $input['permissions'])->where('is_active', true)->pluck('id') as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $permission]);
            }
            DB::table('facility_user_roles')->where('facility_id', $f['id'])->where('user_id', $id)->delete();
            DB::table('facility_user_roles')->insert(['facility_id' => $f['id'], 'user_id' => $id, 'role_id' => $role->id]);
            $user->forceFill(['is_active' => $input['is_active'], 'lock_version' => $user->lock_version + 1])->save();
            if (! $input['is_active']) {
                $user->tokens()->delete();
            }
            app(IdentityCorrections::class)->audit($r, $f, 'reception_account', $id, 'updated', $old, ['is_active' => $input['is_active'], 'permissions' => $input['permissions'], 'reason' => $input['reason']]);

            return $id;
        });
    }
}
