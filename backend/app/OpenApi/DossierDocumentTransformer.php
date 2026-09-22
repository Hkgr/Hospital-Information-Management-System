<?php

namespace App\OpenApi;

use App\Services\Dossiers\DossierPathology;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class DossierDocumentTransformer extends ClinicDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        $text = fn () => new StringType;
        $nullable = fn () => (new StringType)->nullable(true);
        $integer = fn () => new IntegerType;
        $dossierStatus = fn () => (new StringType)->enum(['draft', 'active']);
        $visitStatus = fn () => (new StringType)->enum(['draft', 'complete']);
        $diagnosis = $this->object(['id' => $integer(), 'code' => $text(), 'name' => $text(), 'diagnosed_on' => $nullable(), 'clinic' => $nullable(), 'doctor' => $nullable()]);
        $clinical = $this->object(['id' => $integer(), 'name' => $text(), 'date' => $text()]);
        $visit = $this->object(['id' => $integer(), 'visit_no' => $text(), 'visit_date' => $text(), 'status' => $visitStatus(), 'visit_clinic' => $nullable(), 'attending_doctor' => $nullable(), 'diagnoses' => $this->list($diagnosis)]);
        $catalogItem = $clinical->clone()->addProperty('code', $text());
        $quantified = $catalogItem->clone()->addProperty('quantity', $text());
        $medication = $clinical->clone()->addProperty('dose_text', $nullable())->addProperty('quantity', $nullable())->addProperty('quantity_unit', $nullable());
        $visit->addProperty('services', $this->list($quantified));
        $visit->addProperty('procedures', $this->list($quantified));
        $visit->addProperty('outcomes', $this->list($catalogItem));
        $visit->addProperty('medications', $this->list($medication));
        $visit->addProperty('administered_medications', $this->list($medication));
        $visit->addProperty('is_referred', new BooleanType);
        foreach (['referring_hospital', 'referral_date', 'referral_reason'] as $field) {
            $visit->addProperty($field, $nullable());
        }
        $row = $this->object(['id' => $integer(), 'card_id' => $integer(), 'legacy_without_visits' => new BooleanType, 'code' => $text(), 'opening_date' => $text(), 'status' => $dossierStatus(), 'patient_code' => $text(), 'patient_name' => $text(), 'is_oncology' => new BooleanType, 'visit_count' => $integer(), 'latest_visit_status' => $visitStatus()->nullable(true), 'latest_visit_id' => (new IntegerType)->nullable(true), 'diagnoses' => $this->list($diagnosis)]);
        foreach (['mother_name', 'birth_date', 'phone', 'paper_file_number'] as $field) {
            $row->addProperty($field, $nullable());
        }
        foreach (['gender', 'birth_date_accuracy'] as $field) {
            $row->addProperty($field, $text());
        }
        $personal = $this->object(array_fill_keys(['patient_code', 'first_name', 'family_name', 'father_name', 'mother_name', 'birth_date', 'birth_date_accuracy', 'gender', 'phone', 'alt_phone', 'paper_file_number', 'governorate', 'city', 'address_line', 'displacement_status', 'marital_status', 'permanent_address', 'occupation', 'smoking_status', 'alcohol_status'], $nullable()));
        $oncology = $this->object(['previous_examinations' => $nullable(), 'medication_source' => $nullable(), 'other_organization' => $nullable(), 'selections' => $this->list($this->object(['selection_group' => $text(), 'code' => $text()]))])->nullable(true);
        $detail = $this->object(['id' => $integer(), 'card_id' => $integer(), 'legacy_without_visits' => new BooleanType, 'facility_id' => $integer(), 'code' => $text(), 'opening_date' => $text(), 'status' => $dossierStatus(), 'disability_text' => $nullable(), 'clinical_history' => $nullable(), 'weight_kg' => (new NumberType)->nullable(true), 'height_cm' => (new NumberType)->nullable(true), 'is_oncology' => new BooleanType, 'patient' => $personal, 'oncology' => $oncology, 'visit_count' => $integer(), 'latest_visit' => $visit->clone()->nullable(true)]);
        $meta = $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], $integer()));
        $row->addProperty('procedure_count', $integer());
        $row->addProperty('pathology_status', (new StringType)->enum(array_keys(DossierPathology::DISPOSITIONS)));
        $row->addProperty('pathology_visit_id', (new IntegerType)->nullable(true));
        $visit->addProperty('diagnostic_assessment', (new DossierPathologyDocument)->assessment()->nullable(true));
        $detail->addProperty('pathology_summary', $this->object(['disposition' => $text(), 'visit_id' => $integer(), 'fact_date' => $text()])->nullable(true));
        $row->addProperty('latest_visit_date', $nullable());
        $visit->addProperty('clinical', (new DossierCompletionDocument)->clinical());
        $visit->addProperty('lock_version', $integer());
        foreach (['treatment_count', 'active_treatment_count', 'review_treatment_count'] as $key) {
            $row->addProperty($key, (new IntegerType)->nullable(true));
        }
        foreach (['treatment_modalities', 'next_dose_on', 'last_dose_on'] as $key) {
            $row->addProperty($key, $nullable());
        }
        $detail->addProperty('latest_visit', $visit->clone()->nullable(true));
        $row->addProperty('workflow', (new DossierWorkflowDocument)->workflow());
        $detail->addProperty('workflow', (new DossierWorkflowDocument)->workflow());
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if (! preg_match('#^dossiers(/|$)#', $route)) {
                continue;
            }
            foreach ($path->operations as $op) {
                $op->security = [new SecurityRequirement(['bearerAuth' => []])];
                if (str_starts_with($route, 'dossiers/imports') || $route === 'dossiers/import-template.xlsx') {
                    (new DossierImportDocument)->operation($op, $route);

                    continue;
                }
                if (preg_match('#/(treatment-options|treatment-plans|treatment-sessions|doses|dispensing)(/|$)#', $route)) {
                    (new OncologyDocument)->operation($op, $route);

                    continue;
                }
                if (str_contains($route, '/pathology') || str_ends_with($route, '/diagnostic-assessment')) {
                    (new DossierPathologyDocument)->operation($op, $route);

                    continue;
                }
                if (str_ends_with($route, '/audit')) {
                    (new DossierAuditDocument)->operation($op);

                    continue;
                }
                if (DossierCompletionDocument::matches($route) && ! str_contains($route, '/options/')) {
                    (new DossierCompletionDocument)->operation($op, $route);

                    continue;
                }
                if ($op->method !== 'get' || str_contains($route, '/options') || str_ends_with($route, '/progress')) {
                    (new DossierWorkflowDocument)->operation($op, $route);

                    continue;
                }
                $op->description = 'Browser entry is /patient-cards; legacy /dossiers browser URLs redirect with queries intact. Patient Card read contract: patients is the one global identity; code/patient_code are the same canonical code. id is the local clinical-context route ID; card_id is the global patient ID. Other facility medical contexts and visits remain inaccessible. Requires dossiers.view in the explicit active facility, active account and Sanctum Bearer api ability. All responses private, no-store. Draft and active facility medical contexts are discoverable; status=all (default), draft or active filters before pagination/totals. No inferred legacy links. Latest/count/history use explicitly linked draft or complete, nonvoided visits up to facility today ordered visit_date DESC, id DESC. NULL reporting periods do not exclude visits. Diagnosis dates can be unknown; diagnosis clinic is independent from visit context. Search normalizes whitespace and treats %/_ literally. Writes are separate permission-scoped section endpoints; Phase 3 adds separately authorized clinical, completion, report and attachment operations.';
                $op->description .= ' procedure_count counts nonvoided procedures performed up to facility today on eligible explicitly linked same-patient/facility visits, independent of diagnosis/service joins. workflow actions use saved section progress, initial-visit existence/status and explicit operation capabilities; they do not authorize writes by themselves.';
                $body = $route === 'dossiers' ? $this->object(['data' => $this->list($row), 'meta' => $meta, 'totals' => $this->object(['dossiers' => $integer()])])
                    : (str_ends_with($route, '/visits') ? $this->object(['data' => $this->list($this->object(['id' => $integer(), 'visit_no' => $text(), 'visit_date' => $text(), 'status' => $visitStatus()])), 'meta' => $meta]) : $this->object(['data' => str_contains($route, '/visits/') ? $visit : $detail]));
                if ($route === 'dossiers/visits') {
                    $op->description = 'Facility-scoped actual visit directory for /visits. Requires dossiers.view; same eligible draft/complete nonvoided, nonfuture visits as Patient Cards. Search by normalized patient full name, patient code or visit number with literal wildcard handling. Filters status=all|draft|complete, from/to, sort=visit_date|visit_no|status, direction, page and per_page apply before totals/pagination. No write on opening. Visit classification has been removed.';
                    $body = $this->object(['data' => $this->list($this->object(['id' => $integer(), 'dossier_id' => $integer(), 'visit_no' => $text(), 'visit_date' => $text(), 'status' => $visitStatus(), 'patient_code' => $text(), 'patient_name' => $text()])), 'meta' => $meta, 'totals' => $this->object(['visits' => $integer()])]);
                }
                $op->addResponse(Response::make(200)->setDescription('Authorized Patient Card data')->setContent('application/json', Schema::fromType($body)));
                foreach ([401 => 'Unauthenticated', 403 => 'Missing permission/ability or inactive account/facility', 404 => 'DOSSIER_NOT_FOUND', 422 => 'Invalid query fields', 500 => 'DOSSIERS_UNAVAILABLE; no internal details'] as $status => $description) {
                    $op->addResponse(Response::make($status)->setDescription($description));
                }
            }
        }
    }
}
