<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class DossierWorkflowDocument extends ClinicDocumentTransformer
{
    public function workflow(): ObjectType
    {
        return $this->object(['sections' => $this->list(new BooleanType), 'subsequent_create' => new BooleanType, 'personal_update' => new BooleanType, 'medical_update' => new BooleanType, 'resume_section' => (new IntegerType)->nullable(true),
            'visit' => $this->object(['id' => (new IntegerType)->nullable(true), 'action' => (new StringType)->enum(['create', 'update'])->nullable(true), 'label' => (new StringType)->nullable(true)])]);
    }

    public function operation(Operation $op, string $route): void
    {
        $text = fn () => new StringType;
        $null = fn () => (new StringType)->nullable(true);
        $int = fn () => new IntegerType;
        $write = $op->method !== 'get';
        $op->description = 'Patient Cards (بطاقات المرضى): active account, Sanctum Bearer api ability and dossiers.view in explicit facility. Section writes additionally require dossiers.create/personal.update/medical.update/visits.create/visits.update. Shared identity operations require explicit global patients.search/create/update; shared diagnosis creation requires global diagnoses.create. Every save is transactional with actor/facility request UUID, content fingerprint and optimistic lock. Same UUID/content replays; changed content or stale version = 409, draft retained for explicit review. All responses private, no-store. Phase 3 completion, reports and uploads use separate explicitly authorized endpoints. GET never creates records. Linked patients are never copied or replaced. New visits AND diagnoses store NULL reporting_period_id; historical period links are preserved. Newly assigned clinic/doctor must be active with membership covering visit_date. Omitted saved diagnoses are retained; remove=true requires ID/version/reason and voids with audit. An empty diagnoses array saves an incomplete draft visit. Oncology off requires confirm_hide_oncology and retains historical profile. See backend/docs/patient-card-correction.md.';
        $op->description .= ' Changing visit_date revalidates every retained diagnosis, including omitted rows: unchanged historical contexts may be inactive but must have same-facility membership covering the new date. Existing local clinical context on registration returns DOSSIER_ALREADY_EXISTS (409) with error.existing_dossier_id; no code/date is silently ignored. creation.allowed combines dossiers.create + dossiers.visits.create and at least one global patient path; workflow contains server-derived section/initial-visit actions and resume_section.';
        $op->description .= ' A patient row is the single global card identity; patient_dossiers rows are facility-local medical/progress contexts. card_id is the global patient ID; id remains the scoped legacy route ID. code and patient.patient_code are the same canonical patients.patient_code. First POST atomically creates identity/context/initial draft visit/progress/audit. Explicit visit_date is required; neither opening_date nor today is a fallback. Code is manually entered (max 40) for new persons only; existing-person mode prohibits code and reuses identity. PUT personal requires unchanged canonical code and prohibits initial visit fields. New facility context requires an explicit genuine visit and creates no second patient/card. Clinical contexts, activation and history stay isolated. Legacy aliases return candidate identities for explicit selection, never auto-merge.';
        $personal = ['code' => $text(), 'opening_date' => $text()];
        foreach (['first_name', 'family_name', 'father_name', 'mother_name', 'birth_date', 'birth_date_accuracy', 'gender', 'phone', 'alt_phone', 'address_line', 'displacement_status', 'marital_status', 'permanent_address', 'occupation', 'smoking_status', 'alcohol_status'] as $key) {
            $personal[$key] = $null();
        }
        $personal += ['governorate_id' => $int()->nullable(true), 'city_id' => $int()->nullable(true)];
        $medical = ['disability_text' => $null(), 'clinical_history' => $null(), 'weight_kg' => (new NumberType)->nullable(true), 'height_cm' => (new NumberType)->nullable(true), 'is_oncology' => new BooleanType, 'history' => $this->list((new StringType)->enum(['medical', 'surgical', 'medication', 'family'])), 'treatment' => $this->list((new StringType)->enum(['surgical', 'chemotherapy', 'radiotherapy', 'other'])), 'previous_examinations' => $null(), 'medication_source' => $null(), 'other_organization' => $null()];
        $diagnosis = $this->object(['id' => $int()->nullable(true), 'lock_version' => $int(), 'diagnosis_id' => $int(), 'clinic_id' => $int(), 'diagnosing_staff_id' => $int(), 'diagnosed_on' => $null(), 'remove' => new BooleanType, 'void_reason' => $null()]);
        $diagnosis->required = ['diagnosis_id', 'clinic_id', 'diagnosing_staff_id'];
        $visit = ['visit_date' => $text(), 'is_referred' => new BooleanType, 'referring_hospital' => $null(), 'referral_date' => $null(), 'referral_reason' => $null(), 'diagnoses' => $this->list($diagnosis)];
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
                $fields += $personal + ($op->method === 'post' ? ['person_mode' => (new StringType)->enum(['existing', 'new']), 'patient_id' => $int(), 'visit_date' => $text()] : ['patient_lock_version' => $int()]);
            }
            $type = $this->object($fields);
            // Conditional fields are explained above and validated by FormRequest; never mark every optional value required.
            $type->required = ['facility_id', 'request_id', ...($op->method === 'put' ? ['lock_version'] : [])];
            $type->required = array_merge($type->required, $route === 'dossiers/diagnoses' ? ['code', 'name_ar'] : (str_ends_with($route, '/medical') ? ['is_oncology'] : (str_contains($route, '/visits') ? ['visit_date', 'is_referred', 'diagnoses'] : ['opening_date', ...($op->method === 'post' ? ['person_mode', 'visit_date'] : ['code', 'patient_lock_version', 'first_name', 'family_name', 'birth_date_accuracy', 'gender', 'displacement_status'])])));
            $op->requestBodyObject = (new RequestBodyObject)->setContent('application/json', Schema::fromType($type));
        }
        $progress = $this->object(['section' => (new StringType)->enum(['personal', 'medical', 'visit', 'clinical', 'medications', 'attachments']), 'state' => (new StringType)->enum(['not_started', 'in_progress', 'saved', 'needs_review']), 'last_saved_by' => $int()->nullable(true), 'last_saved_at' => $null(), 'lock_version' => $int(), 'visit_id' => $int()->nullable(true)]);
        $visit['diagnoses'] = $this->list($this->object(['id' => $int(), 'lock_version' => $int(), 'diagnosis_id' => $int(), 'clinic_id' => $int()->nullable(true), 'diagnosing_staff_id' => $int(), 'diagnosed_on' => $null(), 'diagnosis_name' => $text(), 'clinic_name' => $null(), 'doctor_name' => $text()]));
        $snapshot = $this->object(['id' => $int(), 'card_id' => $int(), 'code' => $text(), 'opening_date' => $text(), 'status' => (new StringType)->enum(['draft', 'active']), 'lock_version' => $int(), 'patient' => $this->object(array_diff_key($personal, array_flip(['code', 'opening_date'])) + ['id' => $int(), 'patient_code' => $text(), 'lock_version' => $int()]), 'medical' => $this->object($medical), 'progress' => $this->list($progress), 'visit' => $this->object($visit + ['id' => $int(), 'visit_no' => $text(), 'status' => $text(), 'lock_version' => $int()])->nullable(true)]);
        $choice = $this->object(['id' => $int(), 'code' => $null(), 'name_ar' => $text()]);
        $snapshot->addProperty('workflow', $this->workflow());
        $snapshot->addProperty('clinical', (new DossierCompletionDocument)->clinical());
        $body = $this->object(['data' => $snapshot]);
        if (in_array($route, ['dossiers/diagnoses', 'dossiers/medications'], true)) {
            $body = $this->object(['data' => $choice]);
        } elseif ($route === 'dossiers/options') {
            $body = $this->object(['data' => $this->object(['today' => $text(), 'capabilities' => $this->object(array_fill_keys(['create', 'delete', 'personal_update', 'medical_update', 'visits_create', 'visits_update', 'patients_search', 'patients_create', 'patients_update', 'diagnoses_create', 'clinical_update', 'attachments_view', 'attachments_upload', 'attachments_download', 'attachments_void', 'finalize', 'visits_complete', 'export', 'audit', 'medications_create', 'assessment_update', 'pathology_create', 'pathology_update', 'pathology_void'], new BooleanType)), 'governorates' => $this->list($this->object(['id' => $int(), 'name_ar' => $text()]))])]);
            $body->properties['data']->addProperty('creation', $this->object(['allowed' => new BooleanType, 'reason' => $null()]));
        } elseif (str_contains($route, '/options/')) {
            if ($route === 'dossiers/options/patients') {
                $choice->addProperty('dossier_id', $int()->nullable(true));
            }
            $body = $this->object(['data' => $this->list($choice), 'meta' => $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], $int()))]);
            if ($route === 'dossiers/options/doctors') {
                $body->addProperty('doctor_types_configured', new BooleanType);
            }
        }
        $op->responses = [];
        $created = $op->method === 'post' && ! preg_match('#/(review|complete|void)$#', $route);
        $op->addResponse(Response::make($created ? 201 : 200)->setDescription('Saved domain snapshot or scoped options')->setContent('application/json', Schema::fromType($body)));
        foreach ([401 => 'Unauthenticated', 403 => 'Permission/ability/facility denied', 404 => 'Scoped record unavailable', 409 => 'DOSSIER_VERSION_CONFLICT or UUID content conflict; fetch latest and explicitly review', 422 => 'Arabic field validation errors; draft unchanged', 500 => 'DOSSIERS_UNAVAILABLE; no internal details'] as $status => $message) {
            $response = Response::make($status)->setDescription($message);
            if ($status === 409) {
                $error = $this->object(['code' => $text(), 'message' => $text(), 'existing_dossier_id' => $int()]);
                $error->required = ['code', 'message'];
                $response->setDescription('DOSSIER_VERSION_CONFLICT, or DOSSIER_ALREADY_EXISTS with existing_dossier_id; preserve the draft and offer explicit navigation.')->setContent('application/json', Schema::fromType($this->object(['error' => $error])));
            }
            $op->addResponse($response);
        }
    }
}
