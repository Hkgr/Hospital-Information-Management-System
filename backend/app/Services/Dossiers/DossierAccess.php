<?php

namespace App\Services\Dossiers;

use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DossierAccess
{
    public function facility(User $user, int $id, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('dossiers.view', $entry['permissions'], true) && in_array('dossiers.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['permissions' => $entry['permissions'], 'capabilities' => $this->capabilities($user, $entry['permissions']), 'today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }
        throw new HttpResponseException(response()->json(['error' => ['code' => 'DOSSIER_ACCESS_DENIED', 'message' => 'لا يتوفر لك وصول إلى إضبارات هذه المنشأة.']], 403));
    }

    public function global(User $user, string $permission, bool $require = true): bool
    {
        $allowed = DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('g.user_id', $user->id)->where('r.is_active', true)->where('p.is_active', true)->where('p.code', $permission)->exists();
        if ($require && ! $allowed) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'DOSSIER_ACCESS_DENIED', 'message' => 'هذه العملية على الدليل المشترك تحتاج تفويضًا صريحًا.']], 403));
        }

        return $allowed;
    }

    private function capabilities(User $user, array $permissions): array
    {
        $caps = [];
        foreach (['create', 'personal.update', 'medical.update', 'visits.create', 'visits.update', 'clinical.update', 'attachments.view', 'attachments.upload', 'attachments.download', 'attachments.void', 'finalize', 'visits.complete', 'export'] as $code) {
            $caps[str_replace('.', '_', $code)] = in_array('dossiers.'.$code, $permissions, true);
        }
        $global = DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('g.user_id', $user->id)->where('r.is_active', true)->where('p.is_active', true)->whereIn('p.code', ['patients.search', 'patients.create', 'patients.update', 'diagnoses.create', 'medications.create'])->pluck('p.code')->all();
        foreach (['patients.search', 'patients.create', 'patients.update', 'diagnoses.create', 'medications.create'] as $code) {
            $caps[str_replace('.', '_', $code)] = in_array($code, $global, true);
        }

        return $caps;
    }
}
