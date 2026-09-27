<?php

namespace App\Services\Dossiers;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;
use Illuminate\Http\Exceptions\HttpResponseException;

class DossierAccess
{
    public function facility(User $user, int $id, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('dossiers.view', $entry['permissions'], true) && in_array('dossiers.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['permissions' => $entry['permissions'], 'capabilities' => $this->capabilities($user, $entry['permissions']), 'today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }
        throw new HttpResponseException(response()->json(['error' => ['code' => 'DOSSIER_ACCESS_DENIED', 'message' => 'لا يتوفر لك وصول إلى بطاقات المرضى في هذه المنشأة.']], 403));
    }

    public function global(User $user, string $permission, bool $require = true): bool
    {
        $allowed = app(GlobalAccess::class)->allows($user, $permission);
        if ($require && ! $allowed) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'DOSSIER_ACCESS_DENIED', 'message' => 'هذه العملية على الدليل المشترك تحتاج تفويضًا صريحًا.']], 403));
        }

        return $allowed;
    }

    private function capabilities(User $user, array $permissions): array
    {
        $caps = [];
        foreach (['view', 'create', 'update', 'activate', 'override', 'status', 'schedule', 'administer', 'dispense', 'correct', 'void'] as $action) {
            $caps['treatment_'.$action] = in_array('dossiers.treatment.view', $permissions, true) && in_array('dossiers.treatment.'.$action, $permissions, true);
        }
        foreach (['import.view', 'import.create', 'import.validate', 'import.commit', 'import.download', 'import.cancel', 'create', 'delete', 'personal.update', 'medical.update', 'visits.create', 'visits.update', 'clinical.update', 'attachments.view', 'attachments.upload', 'attachments.download', 'attachments.void', 'finalize', 'visits.complete', 'export', 'audit', 'assessment.update', 'pathology.create', 'pathology.update', 'pathology.void'] as $code) {
            $caps[str_replace('.', '_', $code)] = in_array('dossiers.'.$code, $permissions, true);
        }
        $global = app(GlobalAccess::class)->codes($user);
        foreach (['patients.search', 'patients.create', 'patients.update', 'diagnoses.create', 'medications.create'] as $code) {
            $caps[str_replace('.', '_', $code)] = in_array($code, $global, true);
        }

        return $caps;
    }
}
