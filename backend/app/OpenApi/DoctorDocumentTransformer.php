<?php

namespace App\OpenApi;

use App\Services\Doctors\DoctorCounts;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class DoctorDocumentTransformer extends ClinicDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        $specialty = $this->object(['id' => new IntegerType, 'name_ar' => new StringType]);
        $type = $this->object(['id' => new IntegerType, 'code' => new StringType, 'name_ar' => new StringType]);
        $link = $this->object(['id' => new IntegerType, 'code' => new StringType, 'name_ar' => new StringType, 'starts_on' => (new StringType)->nullable(true), 'is_linked' => new BooleanType, 'can_view' => new BooleanType]);
        $doctor = $this->object(['id' => new IntegerType, 'code' => new StringType, 'name' => new StringType, 'description' => (new StringType)->nullable(true),
            'staff_type' => $type, 'specialties' => $this->list($this->object(['id' => new IntegerType, 'name_ar' => new StringType, 'is_active' => new BooleanType])),
            'license_no' => (new StringType)->nullable(true), 'phone' => (new StringType)->nullable(true), 'is_active' => new BooleanType, 'lock_version' => new IntegerType,
            'clinic_count' => new IntegerType, 'patient_count' => new IntegerType, 'clinics_preview' => $this->list($type), 'patient_count_definition' => (new StringType)->example(DoctorCounts::PATIENT_DEFINITION)]);
        $meta = $this->object(['page' => new IntegerType, 'per_page' => new IntegerType, 'total' => new IntegerType, 'last_page' => new IntegerType]);
        $caps = $this->object(array_fill_keys(['create', 'update', 'delete', 'link', 'export', 'view_clinics'], new BooleanType));
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if ($route !== 'doctors' && ! str_starts_with($route, 'doctors/')) {
                continue;
            }
            foreach ($path->operations as $operation) {
                $operation->responses = [];
                $operation->security = [new SecurityRequirement(['bearerAuth' => []])];
                $operation->description .= "\nSanctum Bearer → active account → api ability → doctors.view in the selected active facility. Directory create/update/delete require doctors.directory.* through an explicit global_user_roles assignment; a facility role or super_admin name alone never grants global authority. Links require facility doctors.link; exports require facility doctors.export. Every response is private, no-store. Active clinic counts/current intervals use the facility timezone and [starts_on, ends_on). Cross-writer relationship mutations increment both staff and clinic versions, with staff then clinic locks in ascending id order. Stale writes return DOCTOR_VERSION_CONFLICT. Re-fetch and explicitly review draft choices, never auto-merge. Reports include all filtered rows and selected columns, up to 1000 rows/5000 links; long text continues in an explicit appendix. Cairo is embedded in PDF and named in XLSX; no patient identities.";
                $isCreate = $route === 'doctors' && $operation->method === 'post';
                if ($isCreate || $operation->method === 'put') {
                    $linksOnly = str_ends_with($route, '/clinics');
                    $fields = ['facility_id' => (new IntegerType)->setMin(1), 'clinic_add_ids' => $this->list((new IntegerType)->setMin(1))->setMax(200)];
                    if (! $isCreate) {
                        $fields += ['lock_version' => (new IntegerType)->setMin(1), 'clinic_remove_ids' => $this->list((new IntegerType)->setMin(1))->setMax(200)];
                    }
                    if (! $linksOnly) {
                        $fields += ['code' => (new StringType)->setMin(1)->setMax(40), 'name' => (new StringType)->setMin(1)->setMax(200), 'description' => (new StringType)->setMax(10000)->nullable(true), 'staff_type_id' => (new IntegerType)->setMin(1), 'specialty_ids' => $this->list((new IntegerType)->setMin(1))->setMax(100), 'license_no' => (new StringType)->setMax(60)->nullable(true), 'phone' => (new StringType)->setMax(30)->nullable(true), 'is_active' => new BooleanType];
                    }
                    $required = ['facility_id'];
                    if (! $isCreate) {
                        $required[] = 'lock_version';
                    }
                    if (! $linksOnly) {
                        $required = array_merge($required, ['code', 'name', 'staff_type_id', 'specialty_ids', 'is_active']);
                    }
                    $body = $this->object($fields)->setRequired($required);
                    $operation->requestBodyObject->setContent('application/json', $document->components->addSchema($linksOnly ? 'UpdateDoctorClinicsRequest' : ($isCreate ? 'CreateDoctorRequest' : 'UpdateDoctorRequest'), Schema::fromType($body)));
                }
                foreach ($operation->parameters as $parameter) {
                    if ($parameter->name === 'format') {
                        $parameter->setSchema(Schema::fromType((new StringType)->enum(['xlsx', 'pdf'])));
                    }
                }
                if ($operation->method === 'delete') {
                    $operation->addResponse(Response::make(204)->setDescription('Unreferenced doctor deleted and audited; no body.'));
                } elseif (str_contains($route, '/export/') || str_ends_with($route, '/report')) {
                    $response = Response::make(200)->setDescription('Private attachment, Content-Disposition filename and X-Report-Number from server.');
                    $response->setContent('application/pdf', Schema::fromType((new StringType)->format('binary')));
                    if (str_contains($route, '/export/')) {
                        $response->setContent('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Schema::fromType((new StringType)->format('binary')));
                    }
                    $operation->addResponse($response);
                } else {
                    $isLinks = str_ends_with($route, '/clinics') && $operation->method === 'get';
                    $isList = $route === 'doctors' && $operation->method === 'get';
                    $data = $isLinks ? $this->list($link) : ($isList ? $this->list($doctor) : $doctor);
                    if ($route === 'doctors/options') {
                        $data = $this->object(['staff_types' => $this->list($type), 'specialties' => $this->list($specialty), 'doctor_types_configured' => new BooleanType, 'capabilities' => $caps]);
                    }
                    $fields = ['data' => $data];
                    if ($isList || $isLinks) {
                        $fields['meta'] = $meta;
                    }
                    if ($route === 'doctors/options/clinics') {
                        $fields['unavailable'] = $this->unavailableChoices();
                        $this->configureLookup($operation);
                    }
                    $operation->addResponse(Response::make($isCreate ? 201 : 200)->setDescription('Professional fields; facility-scoped counts and links.')->setContent('application/json', Schema::fromType($this->object($fields))));
                }
                foreach ([401 => ['UNAUTHENTICATED'], 403 => ['ACCOUNT_INACTIVE', 'MISSING_API_ABILITY', 'DOCTOR_ACCESS_DENIED', 'DOCTOR_DIRECTORY_ACCESS_DENIED'], 404 => ['DOCTOR_NOT_FOUND'], 409 => ['DOCTOR_VERSION_CONFLICT', 'DOCTOR_REFERENCED', 'CLINIC_PERIOD_CONFLICT'], 500 => ['DOCTORS_UNAVAILABLE']] as $status => $codes) {
                    $operation->addResponse(Response::make($status)->setDescription(implode(' / ', $codes))->setContent('application/json', Schema::fromType($this->object(['error' => $this->object(['code' => (new StringType)->enum($codes), 'message' => new StringType])]))));
                }
                $errors = new ObjectType;
                $errors->additionalProperties = $this->list(new StringType);
                $validation = $this->object(['message' => new StringType, 'errors' => $errors]);
                $limit = $this->object(['error' => $this->object(['code' => (new StringType)->enum(['EXPORT_LIMIT_EXCEEDED']), 'message' => new StringType])]);
                $operation->addResponse(Response::make(422)->setDescription('Invalid field values, or explicit export limit.')->setContent('application/json', Schema::fromType((new AnyOf)->setItems([$validation, $limit]))));
            }
        }
    }
}
