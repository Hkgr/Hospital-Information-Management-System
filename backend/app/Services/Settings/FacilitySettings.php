<?php

namespace App\Services\Settings;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\SessionPolicy;
use App\Services\Auth\UserAccessContext;
use App\Services\Clinics\ClinicAudit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FacilitySettings
{
    public function access(User $user, int $id, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('settings.view', $entry['permissions'], true)
                && in_array('settings.'.$action, $entry['permissions'], true)) {
                return $entry;
            }
        }
        throw new HttpResponseException(response()->json(['error' => ['code' => 'SETTINGS_ACCESS_DENIED', 'message' => 'لا يتوفر لك وصول إلى إعدادات المنشأة المطلوبة.']], 403));
    }

    public function show(User $user, int $id): array
    {
        $entry = $this->access($user, $id);
        $row = DB::table('facility_settings')->where('facility_id', $id)->first();
        $system = (bool) app(GlobalAccess::class)->systemRole($user);
        $global = $system ? DB::table('system_session_policy')->where('id', 1)->first() : null;

        return ['facility' => $entry['facility'], 'idle_minutes' => SessionPolicy::seconds($row?->idle_minutes) / 60,
            'lock_version' => $row?->lock_version ?? 1, 'can_update' => in_array('settings.update', $entry['permissions'], true),
            'system_policy' => $system ? ['idle_minutes' => SessionPolicy::seconds($global?->idle_minutes) / 60, 'lock_version' => $global?->lock_version ?? 1] : null];
    }

    public function save(Request $request, array $input, bool $system): array
    {
        DB::transaction(function () use ($request, $input, $system) {
            // Same first policy lock as SessionPolicy. No token is renewed by this write.
            $global = DB::table('system_session_policy')->where('id', 1)->lockForUpdate()->first();
            $entry = $this->access($request->user(), $input['facility_id'], 'update');
            abort_if($system && ! app(GlobalAccess::class)->systemRole($request->user()), 403);
            $id = $entry['facility']['id'];
            $facility = DB::table('facilities')->where('id', $id)->lockForUpdate()->first();
            if ($system) {
                $row = $global;
            } else {
                DB::table('facility_settings')->insertOrIgnore(['facility_id' => $id, 'enabled_modules' => '[]', 'idle_minutes' => 2, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('facility_settings')->where('facility_id', $id)->lockForUpdate()->first();
            }
            if ((int) $row->lock_version !== (int) $input['lock_version']) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'SETTINGS_VERSION_CONFLICT', 'message' => 'عدّل مسؤول آخر الإعدادات. اجلب القيم الحالية وراجع تغييراتك قبل الحفظ مجددًا.']], 409));
            }
            $before = ['idle_minutes' => SessionPolicy::seconds($row->idle_minutes) / 60];
            $after = ['idle_minutes' => (int) $input['idle_minutes']];
            $changedPolicy = $before['idle_minutes'] != $after['idle_minutes'];
            if ($changedPolicy && empty(trim($input['reason'] ?? ''))) {
                throw ValidationException::withMessages(['reason' => 'اكتب سبب تغيير سياسة الجلسة.']);
            }
            if (! $system) {
                $before['name_ar'] = $facility->name_ar;
                $after['name_ar'] = $input['name_ar'];
                DB::table('facilities')->where('id', $id)->update(['name_ar' => $input['name_ar'], 'updated_at' => now()]);
            }
            DB::table($system ? 'system_session_policy' : 'facility_settings')->where('id', $row->id)->update([
                'idle_minutes' => $after['idle_minutes'], 'lock_version' => $row->lock_version + 1, 'updated_at' => now(),
            ]);
            if ($changedPolicy) {
                DB::table('session_policy_changes')->insert(['facility_id' => $system ? null : $id, 'idle_seconds' => $after['idle_minutes'] * 60, 'changed_at' => now()->format('Y-m-d H:i:s.u')]);
            }
            app(ClinicAudit::class)->record($request, $id, $system ? 1 : $id, 'updated', $before,
                $after + ['reason' => $input['reason'] ?? null, 'scope' => $system ? 'system' : 'facility'], $system ? 'system_session_policy' : 'facility_settings');
        }, 3);

        return $this->show($request->user(), $input['facility_id']);
    }
}
