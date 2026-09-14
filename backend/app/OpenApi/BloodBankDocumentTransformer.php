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

class BloodBankDocumentTransformer extends ClinicDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        $text = fn () => new StringType;
        $nullable = fn () => (new StringType)->nullable(true);
        $choice = $this->object(['id' => new IntegerType, 'code' => $text(), 'name_ar' => $text()]);
        $place = $this->object(['id' => new IntegerType, 'name_ar' => $text()]);
        $person = $this->object(array_combine(SaveBloodProfile::PERSON, array_map(fn ($k) => str_ends_with($k, '_id') ? (new IntegerType)->nullable(true) : $nullable(), SaveBloodProfile::PERSON)));
        $screen = $this->object(['analyte' => (new StringType)->enum(['HBsAg', 'HCV', 'HIV']), 'screening_test_id' => (new IntegerType)->nullable(true), 'status' => (new StringType)->enum(['not_requested', 'requested', 'pending', 'complete', 'cancelled']), 'result' => (new StringType)->enum(['negative', 'positive', 'indeterminate'])->nullable(true)]);
        $base = ['id' => new IntegerType, 'kind' => (new StringType)->enum(['donor', 'recipient']), 'code' => $text(), 'name' => $text(), 'blood_group' => $nullable(), 'rh' => $nullable(), 'clinic_name' => $nullable(), 'doctor_name' => $nullable(), 'updated_at' => $nullable()];
        $profile = $this->object($base + ['facility_id' => new IntegerType, 'patient_id' => (new IntegerType)->nullable(true), 'patient_code' => $nullable(), 'person_mode' => (new StringType)->enum(['direct', 'patient']), 'person' => $person, 'beneficiary_entity' => $nullable(), 'clinic_id' => (new IntegerType)->nullable(true), 'responsible_staff_id' => (new IntegerType)->nullable(true), 'blood_component_id' => (new IntegerType)->nullable(true), 'screenings' => $this->list($screen), 'lock_version' => new IntegerType]);
        $donation = $this->object(['id' => new IntegerType, 'donation_code' => (new StringType)->example('DON-20260914-000123'), 'donated_on' => (new StringType)->format('date'), 'blood_group' => (new StringType)->enum(['A', 'B', 'AB', 'O']), 'rh' => (new StringType)->enum(['positive', 'negative']), 'units' => (new StringType)->example('1.0000'), 'status' => $text(), 'lock_version' => new IntegerType, 'voided_at' => $nullable()]);
        $meta = $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], new IntegerType));
        $caps = $this->object(array_fill_keys(['create', 'update', 'donations_create', 'donations_update', 'patients_search'], new BooleanType));
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if ($route !== 'blood-bank' && ! str_starts_with($route, 'blood-bank/')) {
                continue;
            }
            foreach ($path->operations as $operation) {
                if (in_array($operation->method, ['post', 'put'], true)) {
                    $schema = $operation->requestBodyObject->content['application/json'];
                    $template = ($schema instanceof Reference ? $schema->resolve() : $schema)->type->clone();
                    $required = ['facility_id', 'request_id'];
                    if ($operation->method === 'put') {
                        $template->addProperty('lock_version', new IntegerType);
                        $required[] = 'lock_version';
                    } else {
                        unset($template->properties['lock_version']);
                    }
                    if (str_contains($route, '/donations')) {
                        $template->setRequired([...$required, 'donated_on', 'blood_group', 'rh', 'units']);
                        $operation->requestBodyObject->setContent('application/json', Schema::fromType($template));
                    } else {
                        $required = [...$required, 'person_mode', 'clinic_id', 'responsible_staff_id', 'screenings'];
                        if ($operation->method === 'post') {
                            $required[] = 'kind';
                        } else {
                            unset($template->properties['kind']);
                        }
                        $direct = $template->clone();
                        unset($direct->properties['patient_id']);
                        $direct->addProperty('person_mode', (new StringType)->enum(['direct']))->setRequired([...$required, 'first_name', 'family_name', 'gender', 'birth_date_accuracy', 'displacement_status']);
                        $linked = $template->clone();
                        foreach (SaveBloodProfile::PERSON as $key) {
                            unset($linked->properties[$key]);
                        }
                        $linked->addProperty('person_mode', (new StringType)->enum(['patient']))->addProperty('patient_id', new IntegerType)->setRequired([...$required, 'patient_id']);
                        if ($operation->method === 'post') {
                            $linked->addProperty('kind', (new StringType)->enum(['recipient']));
                        }
                        $operation->requestBodyObject->setContent('application/json', Schema::fromType((new AnyOf)->setItems([$direct, $linked])));
                    }
                }
                $operation->security = [new SecurityRequirement(['bearerAuth' => []])];
                $operation->description .= "\nSanctum Bearer api ability, active account and selected active facility blood_bank.view required; every response private, no-store. facility_id is explicit, selected by the same authenticated directoryFacility rule as doctors/clinics; unauthorized URL never falls back. Profiles need blood_bank.create/update; donation writes need blood_bank.donations.create/update. Global patient search/link additionally requires global_user_roles blood_bank.patients.search and facility create or update. No automatic grants. Direct recipient requires first/family names; linked recipient sends patient_id and NO personal fields, never copied. UUID request_id is durable per user/facility: identical replay returns the same entity, changed content returns 409. PUT requires lock_version; resolve conflicts by fetching latest and explicitly reviewing the draft. Codes BD/BR are stable, historical codes retained. Creating a profile creates no donation, transfusion, patient or visit. Screenings are profile-only: complete requires a result; all other statuses require null. Methods must be explicitly mapped to the analyte, unknown stays null. Clinic and current eligible doctor are validated on new/changed assignment; historical unchanged responsibility is retained.";
                if (str_contains($route, '/donations')) {
                    $operation->description .= "\nActual donation only; donated_on cannot exceed facility-local today and must have exactly one covering open reporting period. Correction also requires the original period open. New status is pending; no screening copying, inventory or acceptance. Positive decimal units (up to 4 decimal places). Code DON-YYYYMMDD-ID uses actual date and the untruncated global donation ID padded to at least 6 digits. Date correction atomically reserves the new code, preserves old aliases and audits the same event; search matches current or previous codes, scoped to donor/facility. Only pending, nonvoided donations can be corrected.";
                }
                $fields = ['data' => $profile];
                if ($route === 'blood-bank' && $operation->method === 'get') {
                    $fields = ['data' => $this->list($this->object($base + ['component_name' => $nullable()])), 'meta' => $meta, 'capabilities' => $caps];
                } elseif (str_ends_with($route, '/options')) {
                    $fields = ['data' => $this->object(['facility' => $this->object($choice->properties + ['timezone' => $text()]), 'capabilities' => $caps, 'can_add_doctor' => new BooleanType, 'blood_components' => $this->list($choice), 'screening_tests' => $this->list($this->object(['id' => new IntegerType, 'code' => $text(), 'name_ar' => $text(), 'blood_bank_analyte' => $text()])), 'governorates' => $this->list($place)])];
                } elseif (str_ends_with($route, '/cities')) {
                    $fields = ['data' => $this->list($place)];
                } elseif (preg_match('#/(clinics|doctors|patients)$#', $route)) {
                    $fields = ['data' => $this->list($choice), 'meta' => $meta];
                    if (str_ends_with($route, '/patients')) {
                        $fields['data'] = $this->list($this->object($choice->properties + ['patient_code' => $text(), 'first_name' => $text(), 'family_name' => $text(), 'birth_date' => $nullable()]));
                    }
                } elseif (str_contains($route, '/patients/')) {
                    $fields = ['data' => $this->object($person->properties + ['id' => new IntegerType, 'patient_code' => $text()])];
                } elseif (str_contains($route, '/donations')) {
                    $fields = str_ends_with($route, '/donations') && $operation->method === 'get' ? ['data' => $this->list($donation), 'meta' => $meta] : ['data' => $this->object($donation->properties + ['donor_id' => new IntegerType, 'reporting_period_id' => new IntegerType])];
                } elseif ($operation->method === 'get') {
                    $fields['capabilities'] = $caps;
                }
                $operation->responses = array_values(array_filter($operation->responses ?? [], fn ($r) => (int) ($r instanceof Reference ? $r->resolve() : $r)->code < 200 || (int) ($r instanceof Reference ? $r->resolve() : $r)->code >= 300));
                $operation->addResponse(Response::make($operation->method === 'post' ? 201 : 200)->setDescription('Blood bank response. Lists include server pagination; full-name/code search uses literal % and _.')->setContent('application/json', Schema::fromType($this->object($fields))));
                foreach ([401 => ['UNAUTHENTICATED'], 403 => ['ACCOUNT_INACTIVE', 'MISSING_API_ABILITY', 'BLOOD_BANK_ACCESS_DENIED', 'BLOOD_BANK_PATIENT_ACCESS_DENIED'], 404 => ['BLOOD_BANK_NOT_FOUND'], 409 => ['BLOOD_BANK_VERSION_CONFLICT', 'BLOOD_BANK_REQUEST_CONFLICT', 'BLOOD_BANK_STATE_CONFLICT', 'BLOOD_BANK_DUPLICATE'], 500 => ['BLOOD_BANK_UNAVAILABLE']] as $status => $codes) {
                    $operation->addResponse(Response::make($status)->setDescription(implode(' / ', $codes))->setContent('application/json', Schema::fromType($this->object(['error' => $this->object(['code' => (new StringType)->enum($codes), 'message' => $text()])]))));
                }
                $errors = new ObjectType;
                $errors->additionalProperties = $this->list($text());
                $operation->addResponse(Response::make(422)->setDescription('Invalid fields, relationship, unavailable reporting period, future date or inconsistent screening.')->setContent('application/json', Schema::fromType($this->object(['message' => $text(), 'errors' => $errors]))));
            }
        }
    }
}
