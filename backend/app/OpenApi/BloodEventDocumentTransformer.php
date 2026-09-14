<?php

namespace App\OpenApi;

use App\Http\Requests\BloodBank\SaveBloodProfile;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class BloodEventDocumentTransformer extends ClinicDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        $text = fn () => new StringType;
        $nullable = fn () => (new StringType)->nullable(true);
        $integer = fn () => (new IntegerType)->nullable(true);
        $totals = $this->object(array_fill_keys(['donations', 'benefits', 'unique_people'], new IntegerType));
        $meta = $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], new IntegerType));
        $screen = $this->object(['analyte' => (new StringType)->enum(['HBsAg', 'HCV', 'HIV']), 'status' => (new StringType)->enum(['not_requested', 'requested', 'pending', 'complete', 'cancelled']), 'result' => $nullable(), 'screening_test_id' => $integer()]);
        $personal = $this->object(array_combine([...SaveBloodProfile::PERSON, ...SaveBloodProfile::MANUAL_ADDRESS], array_map(fn ($k) => str_ends_with($k, '_id') ? $integer() : $nullable(), [...SaveBloodProfile::PERSON, ...SaveBloodProfile::MANUAL_ADDRESS])));
        $personInput = $personal->clone()->addProperty('person_mode', (new StringType)->enum(['direct']))
            ->addProperty('blood_group', (new StringType)->enum(['A', 'B', 'AB', 'O'])->nullable(true))
            ->addProperty('rh', (new StringType)->enum(['positive', 'negative'])->nullable(true))
            ->setRequired(['person_mode', 'first_name', 'family_name', 'birth_date_accuracy', 'gender', 'displacement_status']);
        $linkedInput = $this->object(['person_mode' => (new StringType)->enum(['patient']), 'patient_id' => new IntegerType])
            ->addProperty('blood_group', $personInput->properties['blood_group'])->addProperty('rh', $personInput->properties['rh']);
        $person = $this->object(['id' => new IntegerType, 'facility_id' => new IntegerType, 'code' => $text(), 'name' => $text(), 'patient_id' => $integer(), 'patient_code' => $nullable(), 'person' => $personal, 'blood_group' => $nullable(), 'rh' => $nullable(), 'is_active' => new BooleanType, 'lock_version' => new IntegerType, 'aliases' => $this->list($text()), 'governorate_name' => $nullable(), 'city_name' => $nullable(), 'legacy_screenings' => $this->list($screen), 'totals' => $totals]);
        $fields = ['id' => new IntegerType, 'person_id' => new IntegerType, 'facility_id' => new IntegerType, 'person_code' => $text(), 'patient_code' => $nullable(), 'name' => $text(), 'code' => $text(), 'kind' => (new StringType)->enum(['donation', 'benefit']), 'benefit_kind' => (new StringType)->enum(['issue', 'transfusion'])->nullable(true), 'issue_event_id' => $integer(), 'occurred_on' => (new StringType)->format('date'), 'quantity' => (new StringType)->example('0.4500'), 'quantity_unit' => (new StringType)->enum(['kg', 'unit']), 'blood_group' => $nullable(), 'rh' => $nullable(), 'blood_component_id' => $integer(), 'component_name' => $nullable(), 'clinic_id' => $integer(), 'clinic_name' => $nullable(), 'responsible_staff_id' => $integer(), 'doctor_name' => $nullable(), 'beneficiary_entity' => $nullable(), 'entity_address' => $nullable(), 'legacy_address' => $nullable(), 'status' => $text(), 'voided_at' => $nullable(), 'lock_version' => new IntegerType];
        $fields += ['blood_donation_id' => $integer(), 'blood_transfusion_id' => $integer(), 'reporting_period_id' => $integer(), 'legacy' => new IntegerType, 'entered_by' => new IntegerType, 'updated_by' => $integer(), 'created_at' => $nullable(), 'updated_at' => $nullable()];
        $event = $this->object($fields + ['screenings' => $this->list($screen), 'aliases' => $this->list($text()), 'linked_transfusion_id' => $integer(), 'legacy_screenings' => $this->list($this->object(['name_ar' => $text(), 'tested_on' => $nullable()]))]);
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if (! preg_match('#^blood-bank/(events|people|legacy)(/|$)#', $route)) {
                continue;
            }
            foreach ($path->operations as $op) {
                $op->security = [new SecurityRequirement(['bearerAuth' => []])];
                if (in_array($op->method, ['post', 'put'], true) && isset($op->requestBodyObject->content['application/json'])) {
                    $schema = $op->requestBodyObject->content['application/json'];
                    $input = ($schema instanceof Reference ? $schema->resolve() : $schema)->type->clone();
                    $input->addProperty('person', new AnyOf([$personInput, $linkedInput]));
                    if ($route === 'blood-bank/events' && $op->method === 'post') {
                        $existing = $input->clone();
                        unset($existing->properties['person']);
                        $existing->setRequired([...array_diff($existing->required, ['person', 'lock_version']), 'person_id']);
                        $new = $input->clone();
                        unset($new->properties['person_id']);
                        $new->setRequired([...array_diff($new->required, ['person_id', 'lock_version']), 'person']);
                        $input = new AnyOf([$existing, $new]);
                    }
                    $op->requestBodyObject->setContent('application/json', Schema::fromType($input));
                }
                $op->description = 'Explicit facility_id uses shared directoryFacility and blood_bank.view, active facility/account and Sanctum Bearer api ability. Every response private, no-store. POST events atomically reuses person_id OR validates nested person and creates person + event together. No name/phone identity merging; linked person sends person_mode=patient and patient_id without copied personal/address fields and additionally requires global blood_bank.patients.search. New direct person uses person_mode=direct, first_name, family_name, gender, birth_date_accuracy, displacement_status; optional personal/address fields follow existing directory rules. New person needs blood_bank.create, person edits blood_bank.update; donation writes need blood_bank.donations.create/update; benefit writes need blood_bank.benefits.create/update. No automatic grants. Required positive quantity: DECIMAL(18,4), at most 14 integer and 4 fractional digits, no default; new events require quantity_unit=kg. Historical unit remains unit on edit; no conversion or mixed-unit totals. Benefit requires benefit_kind=issue|transfusion. Transfusion additionally requires explicit benefit_link_mode=independent|linked; linked requires issue_event_id for same person/facility/component, earlier or same date, no existing transfusion. Relationship/type/person are immutable after creation. Issue creates no blood_transfusion. Linked stages are two ledger rows but one distinct benefit (COALESCE(issue_event_id,id)). Only actual nonvoided events count. Search/filters precede pagination and totals. available_issues returns unlinked nonvoided issues. Required clinic/current eligible doctor/component, actual date no later than facility-local today without a reporting-period prerequisite. New records have reporting_period_id=null; existing period links are historical metadata only and remain unchanged on date corrections. No period is selected, created or opened. New-person registration initializes person blood_group/rh from the single event input; existing person data is never overwritten. Event blood type and screenings are independent of current person and other events. UUID request_id is durable per user/facility: identical replay returns same record, different content 409. PUT lock_version requires explicit draft/latest conflict review. Codes DON/ISS/TRF-YYYYMMDD-ID retain date-correction aliases. Screening inputs contain analyte+status only, optional, up to three; omissions never erase historical results/methods. Unmapped legacy data returns 409 BLOOD_BANK_RECONCILIATION_REQUIRED until reviewed reconciliation. Legacy profiles alone create no events.';
                $op->responses = array_values(array_filter($op->responses ?? [], fn ($r) => (int) ($r instanceof Reference ? $r->resolve() : $r)->code >= 300));
                $report = str_contains($route, '/report/') || str_contains($route, '/export/');
                if ($report) {
                    $op->description .= ' Reports require blood_bank.export. Entire filtered sorted ledger, not current page; person report all events for that person. Limit 1000 events returns 422 EXPORT_LIMIT_EXCEEDED, never truncation. Distinguishes issue/actual transfusion, stage link, quantity and original unit. Current patient identity is read, event blood type is historical. PDF with embedded Cairo or true XLSX with textual codes and numeric quantities.';
                    $op->addResponse(Response::make(200)->setDescription('Private attachment with X-Report-Number')->setContent('application/pdf', Schema::fromType((new StringType)->format('binary')))->setContent('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Schema::fromType((new StringType)->format('binary'))));
                } else {
                    $body = ['data' => str_starts_with($route, 'blood-bank/people/') ? $person : $event];
                    if ($route === 'blood-bank/events' && $op->method === 'get') {
                        $body = ['data' => $this->list($this->object($fields)), 'meta' => $meta, 'totals' => $totals];
                    }
                    if ($route === 'blood-bank/people') {
                        $body = ['data' => $this->list($this->object(['id' => new IntegerType, 'code' => $text(), 'name' => $text(), 'name_ar' => $text(), 'patient_code' => $nullable(), 'blood_group' => $nullable(), 'rh' => $nullable(), 'is_active' => new IntegerType])), 'meta' => $meta];
                    }
                    if (str_starts_with($route, 'blood-bank/legacy/')) {
                        $body = ['data' => $this->object(['person_id' => new IntegerType, 'event_id' => $integer()])];
                    }
                    $op->addResponse(Response::make($op->method === 'post' ? 201 : 200)->setDescription('Blood bank response')->setContent('application/json', Schema::fromType($this->object($body))));
                }
                foreach ([401 => 'UNAUTHENTICATED', 403 => 'BLOOD_BANK_ACCESS_DENIED / BLOOD_BANK_PATIENT_ACCESS_DENIED / ACCOUNT_INACTIVE / MISSING_API_ABILITY', 404 => 'BLOOD_BANK_NOT_FOUND', 409 => 'BLOOD_BANK_VERSION_CONFLICT / BLOOD_BANK_REQUEST_CONFLICT / BLOOD_BANK_STATE_CONFLICT / BLOOD_BANK_RECONCILIATION_REQUIRED', 500 => 'BLOOD_BANK_UNAVAILABLE'] as $status => $description) {
                    $op->addResponse(Response::make($status)->setDescription($description)->setContent('application/json', Schema::fromType($this->object(['error' => $this->object(['code' => $text(), 'message' => $text()])]))));
                }
                $errors = new ObjectType;
                $errors->additionalProperties = $this->list($text());
                $validation = $this->object(['message' => $text(), 'errors' => $errors]);
                $op->addResponse(Response::make(422)->setDescription('Invalid quantity, fields, stage link, relationship or export limit.')->setContent('application/json', Schema::fromType($report ? new AnyOf([$validation, $this->object(['error' => $this->object(['code' => (new StringType)->enum(['EXPORT_LIMIT_EXCEEDED']), 'message' => $text()])])]) : $validation)));
            }
        }
    }
}
