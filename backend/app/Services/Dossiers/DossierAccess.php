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
        $action = ['view' => 'medical.view', 'visits.update' => 'visits.draft.update'][$action] ?? $action;
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('dossiers.medical.view', $entry['permissions'], true) && in_array('dossiers.'.$action, $entry['permissions'], true)
                && (in_array($action, ['medical.view', 'audit', 'delete'], true) || in_array('dossiers.visits.view', $entry['permissions'], true))) {
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
        foreach (['visits.view', 'visits.draft.update', 'diagnoses.update', 'services.update', 'procedures.update', 'prescriptions.update', 'outcomes.update', 'treatment.schedule.create', 'treatment.schedule.update', 'treatment.administration.correct', 'treatment.administration.void', 'treatment.dispensing.correct', 'treatment.dispensing.void'] as $code) {
            $caps[str_replace('.', '_', $code)] = in_array('dossiers.'.$code, $permissions, true);
        }
        $caps['visits_update'] = $caps['visits_draft_update'];
        $caps['clinical_update'] = $caps['services_update'] || $caps['procedures_update'] || $caps['prescriptions_update'] || $caps['outcomes_update'];
        foreach (['patients.search', 'patients.create', 'patients.update', 'diagnoses.create', 'medications.create'] as $code) {
            $caps[str_replace('.', '_', $code)] = in_array($code, $global, true);
        }

        // Write responses include the saved visit snapshot. Do not advertise an
        // action whose response the current role is not authorized to read.
        if (! $caps['visits_view']) {
            foreach ($caps as $key => $value) {
                if (! in_array($key, ['audit', 'delete'], true)) {
                    $caps[$key] = false;
                }
            }
        }
        foreach (['schedule_create', 'schedule_update', 'administration_correct', 'administration_void', 'dispensing_correct', 'dispensing_void'] as $action) {
            $caps['treatment_'.$action] = $caps['treatment_'.$action] && $caps['treatment_view'];
        }

        return $caps;
    }
}
