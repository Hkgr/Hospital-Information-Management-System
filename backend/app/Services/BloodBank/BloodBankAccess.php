<?php

namespace App\Services\BloodBank;

use App\Exceptions\BloodBankException;
use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Illuminate\Support\Facades\DB;

class BloodBankAccess
{
    public function facility(User $user, int $id, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('blood_bank.view', $entry['permissions'], true) && in_array('blood_bank.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['permissions' => $entry['permissions'], 'today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }
        throw new BloodBankException('BLOOD_BANK_ACCESS_DENIED', 'ليس لديك وصول إلى بنك الدم في المنشأة المطلوبة.', 403);
    }

    public function canSearchPatients(User $user): bool
    {
        return DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('g.user_id', $user->id)->where('r.is_active', true)->where('p.is_active', true)->where('p.code', 'blood_bank.patients.search')->exists();
    }

    public function patients(User $user, array $facility): void
    {
        if (! $this->canSearchPatients($user) || (! in_array('blood_bank.create', $facility['permissions'], true) && ! in_array('blood_bank.update', $facility['permissions'], true))) {
            throw new BloodBankException('BLOOD_BANK_PATIENT_ACCESS_DENIED', 'البحث في مرضى المشفى يحتاج تفويضًا صريحًا للربط ببنك الدم.', 403);
        }
    }

    public function capabilities(User $user, array $facility): array
    {
        $caps = [];
        foreach (['create', 'update', 'export', 'donations.create', 'donations.update'] as $action) {
            $caps[str_replace('.', '_', $action)] = in_array('blood_bank.'.$action, $facility['permissions'], true);
        }
        $caps['patients_search'] = ($caps['create'] || $caps['update']) && $this->canSearchPatients($user);

        return $caps;
    }
}
