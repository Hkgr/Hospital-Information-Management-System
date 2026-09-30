<?php

namespace App\Services\BloodBank;

use App\Exceptions\BloodBankException;
use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;

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
        return app(GlobalAccess::class)->allows($user, 'blood_bank.patients.search');
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
        foreach (['create', 'update', 'export', 'donations.create', 'donations.update', 'benefits.create', 'benefits.update', 'issue.create', 'issue.update', 'transfusion.create', 'transfusion.update'] as $action) {
            $caps[str_replace('.', '_', $action)] = in_array('blood_bank.'.$action, $facility['permissions'], true);
        }
        $caps['patients_search'] = ($caps['create'] || $caps['update']) && $this->canSearchPatients($user);
        $caps['benefits_create'] = $caps['issue_create'] || $caps['transfusion_create'];
        $caps['benefits_update'] = $caps['issue_update'] || $caps['transfusion_update'];

        return $caps;
    }
}
