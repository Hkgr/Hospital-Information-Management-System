<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
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
        $row = $this->object(['id' => $integer(), 'code' => $text(), 'opening_date' => $text(), 'status' => $dossierStatus(), 'patient_code' => $text(), 'patient_name' => $text(), 'is_oncology' => new BooleanType, 'visit_count' => $integer(), 'latest_visit_status' => $visitStatus()->nullable(true), 'latest_visit_id' => (new IntegerType)->nullable(true), 'diagnoses' => $this->list($diagnosis)]);
        $personal = $this->object(array_fill_keys(['patient_code', 'first_name', 'family_name', 'father_name', 'mother_name', 'birth_date', 'birth_date_accuracy', 'gender', 'phone', 'alt_phone', 'governorate', 'city', 'address_line', 'displacement_status'], $nullable()));
        $oncology = $this->object(['previous_examinations' => $nullable(), 'medication_source' => $nullable(), 'other_organization' => $nullable(), 'selections' => $this->list($this->object(['selection_group' => $text(), 'code' => $text()]))])->nullable(true);
        $detail = $this->object(['id' => $integer(), 'facility_id' => $integer(), 'code' => $text(), 'opening_date' => $text(), 'status' => $dossierStatus(), 'disability_text' => $nullable(), 'clinical_history' => $nullable(), 'is_oncology' => new BooleanType, 'patient' => $personal, 'oncology' => $oncology, 'visit_count' => $integer(), 'latest_visit' => $visit->clone()->nullable(true)]);
        $meta = $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], $integer()));
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if (! preg_match('#^dossiers(/|$)#', $route)) {
                continue;
            }
            foreach ($path->operations as $op) {
                $op->security = [new SecurityRequirement(['bearerAuth' => []])];
                $op->description = 'Read-only Phase 1. Requires dossiers.view in the explicit active facility, active account and Sanctum Bearer api ability. All responses private, no-store. Draft and active dossiers are discoverable; status=all (default), draft or active filters the list before pagination/totals. No inferred legacy links. Latest/count/history use explicitly linked draft or complete, nonvoided visits up to facility today ordered visit_date DESC, id DESC. Diagnosis dates can be unknown; diagnosis clinic is independent from visit context. List search normalizes whitespace and treats %/_ literally. Server pagination and full filtered total, deterministic sort. Previous visits are paginated. No creation, edit, reports or attachments in this phase.';
                $body = $route === 'dossiers' ? $this->object(['data' => $this->list($row), 'meta' => $meta, 'totals' => $this->object(['dossiers' => $integer()])])
                    : (str_ends_with($route, '/visits') ? $this->object(['data' => $this->list($this->object(['id' => $integer(), 'visit_no' => $text(), 'visit_date' => $text(), 'status' => $visitStatus()])), 'meta' => $meta]) : $this->object(['data' => str_contains($route, '/visits/') ? $visit : $detail]));
                $op->addResponse(Response::make(200)->setDescription('Authorized dossier data')->setContent('application/json', Schema::fromType($body)));
                foreach ([401 => 'Unauthenticated', 403 => 'Missing permission/ability or inactive account/facility', 404 => 'DOSSIER_NOT_FOUND', 422 => 'Invalid query fields', 500 => 'DOSSIERS_UNAVAILABLE; no internal details'] as $status => $description) {
                    $op->addResponse(Response::make($status)->setDescription($description));
                }
            }
        }
    }
}
