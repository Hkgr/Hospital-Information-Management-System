<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class DossierWorkflowDocument extends ClinicDocumentTransformer
{
    public function operation(Operation $op, string $route): void
    {
        $text = fn () => new StringType;
        $null = fn () => (new StringType)->nullable(true);
        $int = fn () => new IntegerType;
        $write = $op->method !== 'get';
        $op->description = 'Dossier Phase 2: active account, Sanctum Bearer api ability and dossiers.view in explicit facility. Section writes additionally require dossiers.create/personal.update/medical.update/visits.create/visits.update. Shared identity operations require explicit global patients.search/create/update; shared diagnosis creation requires global diagnoses.create. Every save is transactional with actor/facility request UUID, content fingerprint and optimistic lock. Same UUID/content replays; changed content or stale version = 409, draft retained for explicit review. All responses private, no-store. No activation/completion/report/upload. GET never creates records. Linked patients are never copied or replaced. New visits AND diagnoses store NULL reporting_period_id; historical period links are preserved. Newly assigned clinic/doctor must be active with membership covering visit_date. Omitted saved diagnoses are retained; remove=true requires ID/version/reason and voids with audit. An empty diagnoses array saves an incomplete draft visit. Oncology off requires confirm_hide_oncology and retains historical profile. See backend/docs/patient-dossiers-phase-two.md.';
        $personal = ['code' => $text(), 'opening_date' => $text()];
        foreach (['first_name', 'family_name', 'father_name', 'mother_name', 'birth_date', 'birth_date_accuracy', 'gender', 'phone', 'alt_phone', 'address_line', 'displacement_status'] as $key) {
            $personal[$key] = $null();
        }
        $personal += ['governorate_id' => $int()->nullable(true), 'city_id' => $int()->nullable(true)];
        $medical = ['disability_text' => $null(), 'clinical_history' => $null(), 'is_oncology' => new BooleanType, 'history' => $this->list((new StringType)->enum(['medical', 'surgical', 'medication', 'family'])), 'treatment' => $this->list((new StringType)->enum(['surgical', 'chemotherapy', 'radiotherapy', 'other'])), 'previous_examinations' => $null(), 'medication_source' => $null(), 'other_organization' => $null()];
        $diagnosis = $this->object(['id' => $int()->nullable(true), 'lock_version' => $int(), 'diagnosis_id' => $int(), 'clinic_id' => $int(), 'diagnosing_staff_id' => $int(), 'diagnosed_on' => $null(), 'remove' => new BooleanType, 'void_reason' => $null()]);
        $diagnosis->required = ['diagnosis_id', 'clinic_id', 'diagnosing_staff_id'];
        $visit = ['visit_date' => $text(), 'visit_type_id' => $int(), 'is_referred' => new BooleanType, 'referring_hospital' => $null(), 'referral_date' => $null(), 'referral_reason' => $null(), 'diagnoses' => $this->list($diagnosis)];
        if ($write) {
            $fields = ['facility_id' => $int(), 'request_id' => (new StringType)->format('uuid')];
            if ($op->method === 'put') {
                $fields['lock_version'] = $int();
            }
            if ($route === 'dossiers/diagnoses') {
                $fields += ['code' => $text(), 'name_ar' => $text()];
            } elseif (str_ends_with($route, '/medical')) {
                $fields += $medical + ['confirm_hide_oncology' => new BooleanType];
            } elseif (str_contains($route, '/visits')) {
                $fields += $visit;
            } else {
                $fields += $personal + ($op->method === 'post' ? ['person_mode' => (new StringType)->enum(['existing', 'new']), 'patient_id' => $int()] : ['patient_lock_version' => $int()]);
            }
            $type = $this->object($fields);
            // Conditional fields are explained above and validated by FormRequest; never mark every optional value required.
            $type->required = ['facility_id', 'request_id', ...($op->method === 'put' ? ['lock_version'] : [])];
            $type->required = array_merge($type->required, $route === 'dossiers/diagnoses' ? ['code', 'name_ar'] : (str_ends_with($route, '/medical') ? ['is_oncology'] : (str_contains($route, '/visits') ? ['visit_date', 'visit_type_id', 'is_referred', 'diagnoses'] : ['code', 'opening_date', ...($op->method === 'post' ? ['person_mode'] : ['patient_lock_version', 'first_name', 'family_name', 'birth_date_accuracy', 'gender', 'displacement_status'])])));
            $op->requestBodyObject = (new RequestBodyObject)->setContent('application/json', Schema::fromType($type));
        }
        $progress = $this->object(['section' => (new StringType)->enum(['personal', 'medical', 'visit']), 'state' => (new StringType)->enum(['not_started', 'in_progress', 'saved', 'needs_review']), 'last_saved_by' => $int()->nullable(true), 'last_saved_at' => $null(), 'lock_version' => $int(), 'visit_id' => $int()->nullable(true)]);
        $visit['diagnoses'] = $this->list($this->object(['id' => $int(), 'lock_version' => $int(), 'diagnosis_id' => $int(), 'clinic_id' => $int()->nullable(true), 'diagnosing_staff_id' => $int(), 'diagnosed_on' => $null(), 'diagnosis_name' => $text(), 'clinic_name' => $null(), 'doctor_name' => $text()]));
        $snapshot = $this->object(['id' => $int(), 'code' => $text(), 'opening_date' => $text(), 'status' => (new StringType)->enum(['draft', 'active']), 'lock_version' => $int(), 'patient' => $this->object(array_diff_key($personal, array_flip(['code', 'opening_date'])) + ['id' => $int(), 'patient_code' => $text(), 'lock_version' => $int()]), 'medical' => $this->object($medical), 'progress' => $this->list($progress), 'visit' => $this->object($visit + ['id' => $int(), 'visit_no' => $text(), 'status' => $text(), 'lock_version' => $int()])->nullable(true)]);
        $choice = $this->object(['id' => $int(), 'code' => $null(), 'name_ar' => $text()]);
        $body = $this->object(['data' => $snapshot]);
        if ($route === 'dossiers/diagnoses') {
            $body = $this->object(['data' => $choice]);
        } elseif ($route === 'dossiers/options') {
            $body = $this->object(['data' => $this->object(['today' => $text(), 'capabilities' => $this->object(array_fill_keys(['create', 'personal_update', 'medical_update', 'visits_create', 'visits_update', 'patients_search', 'patients_create', 'patients_update', 'diagnoses_create'], new BooleanType)), 'governorates' => $this->list($this->object(['id' => $int(), 'name_ar' => $text()])), 'visit_types' => $this->list($choice)])]);
        } elseif (str_contains($route, '/options/')) {
            if ($route === 'dossiers/options/patients') {
                $choice->addProperty('dossier_id', $int()->nullable(true));
            }
            $body = $this->object(['data' => $this->list($choice), 'meta' => $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], $int()))]);
        }
        $op->responses = [];
        $op->addResponse(Response::make($op->method === 'post' ? 201 : 200)->setDescription('Saved domain snapshot or scoped options')->setContent('application/json', Schema::fromType($body)));
        foreach ([401 => 'Unauthenticated', 403 => 'Permission/ability/facility denied', 404 => 'Scoped record unavailable', 409 => 'DOSSIER_VERSION_CONFLICT or UUID content conflict; fetch latest and explicitly review', 422 => 'Arabic field validation errors; draft unchanged', 500 => 'DOSSIERS_UNAVAILABLE; no internal details'] as $status => $message) {
            $op->addResponse(Response::make($status)->setDescription($message));
        }
    }
}
