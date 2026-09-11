<?php

namespace App\OpenApi;

use App\Http\Responses\AuthError;
use App\Services\Clinics\ClinicCounts;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

class ClinicDocumentTransformer
{
    protected function object(array $fields): ObjectType
    {
        $type = new ObjectType;
        foreach ($fields as $key => $value) {
            $type->addProperty($key, $value);
        }

        return $type->setRequired(array_keys($fields));
    }

    protected function list(Type $item): ArrayType
    {
        return (new ArrayType)->setItems($item);
    }

    protected function lifecycleDescription(): string
    {
        return '\nDefault lists exclude archived_at records; status=archived explicitly lists them. Detail/history remain readable with existing view permission. Deletion preview requires delete authority; organizational_links counts periods in the authorized facility only, has_other_references is a boolean without identities. DELETE rechecks all references transactionally; 204 means hard deletion only. POST archive requires delete permission, preserves records/history, closes current periods at the facility date and cancels future periods with ends_on=starts_on. POST deactivate changes activity only; reactivate validates an active configured doctor type. POST restore clears archived_at but keeps is_active=false and never reopens periods. These three actions require update authority. Doctor actions are GLOBAL and require explicit doctors.directory.*; clinic actions require the facility permission. All mutations require lock_version. Stale/duplicate state returns 409 VERSION_CONFLICT/STATE_CONFLICT, no implicit retries or archive fallback. Counts/new assignments require both endpoints active and unarchived. link-history is paginated and scoped to the selected facility; names and half-open periods survive archive.';
    }

    protected function deletionPreviewSchema(): ObjectType
    {
        return $this->object(['action' => (new StringType)->enum(['delete', 'archive']), 'organizational_links' => new IntegerType, 'has_other_references' => new BooleanType, 'lock_version' => new IntegerType, 'archived' => new BooleanType]);
    }

    protected function historySchema(): ObjectType
    {
        return $this->object(['id' => new IntegerType, 'code' => new StringType, 'name' => new StringType, 'starts_on' => new StringType, 'ends_on' => (new StringType)->nullable(true)]);
    }

    protected function unavailableChoices(): ArrayType
    {
        return $this->list($this->object(['id' => new IntegerType, 'reason' => (new StringType)->enum(['INACTIVE', 'UNAVAILABLE'])]));
    }

    protected function lookupDescription(): string
    {
        return '\nOptional ids[] performs a complete stable-ID lookup: 1–100 positive safe integers per request, duplicates coalesced. Do not combine with search/page/per_page. Split up to 200 additions + 200 removals into four batches. data contains current eligible identities/linkage; unavailable contains only requested id and reason. INACTIVE means an in-scope inactive record/type; UNAVAILABLE deliberately conflates missing, out-of-scope and ineligible records. Clinic results and all link state are limited to the authorized facility; staff choices retain the existing global eligible professional directory boundary. Re-check parent lock_version after all batches; explicitly review and save, never automatically rebase.';
    }

    protected function configureLookup($operation): void
    {
        $operation->description .= $this->lookupDescription();
        foreach ($operation->parameters as $parameter) {
            if ($parameter->name === 'ids[]') {
                $parameter->required(false)->setStyle('form')->setExplode(true)
                    ->description('Optional complete batch. Repeated ids[] keys; 1–100 entries, deduplicated by the server. Incompatible with search/page/per_page.')
                    ->setSchema(Schema::fromType($this->list((new IntegerType)->setMin(1)->setMax(9007199254740991))->setMin(1)->setMax(100)));
            }
        }
    }

    public function __invoke(OpenApi $document): void
    {
        $specialty = $this->object(['id' => new IntegerType, 'name_ar' => new StringType]);
        $clinic = $this->object([
            'id' => new IntegerType, 'facility_id' => new IntegerType, 'code' => (new StringType)->example('001'),
            'name_ar' => (new StringType)->example('عيادة اختبارية'), 'description' => (new StringType)->nullable(true),
            'specialty' => (clone $specialty)->nullable(true), 'archived_at' => (new StringType)->nullable(true), 'is_active' => new BooleanType, 'lock_version' => (new IntegerType)->example(1),
            'doctor_count' => new IntegerType, 'patient_count' => new IntegerType,
            'doctors_preview' => $this->list($this->object(['id' => new IntegerType, 'name' => new StringType])),
            'patient_count_definition' => (new StringType)->example(ClinicCounts::PATIENT_DEFINITION),
        ]);
        $doctor = $this->object(['id' => new IntegerType, 'code' => new StringType, 'name' => new StringType,
            'starts_on' => (new StringType)->nullable(true), 'is_linked' => new BooleanType, 'specialties' => $this->list($specialty)]);
        $meta = $this->object(['page' => new IntegerType, 'per_page' => new IntegerType, 'total' => new IntegerType, 'last_page' => new IntegerType]);
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if ($route !== 'clinics' && ! str_starts_with($route, 'clinics/')) {
                continue;
            }
            foreach ($path->operations as $operation) {
                $operation->responses = array_values(array_filter($operation->responses ?? [], function ($response) {
                    $resolved = $response instanceof Reference ? $response->resolve() : $response;

                    return (int) $resolved->code < 200 || (int) $resolved->code >= 300;
                }));
                $operation->security = [new SecurityRequirement(['bearerAuth' => []])];
                if (($route === 'clinics' && $operation->method === 'post') || $operation->method === 'put') {
                    $bodySchema = $operation->requestBodyObject->content['application/json'];
                    $bodySchema = $bodySchema instanceof Reference ? $bodySchema->resolve() : $bodySchema;
                    $body = $bodySchema->type->clone();
                    if ($operation->method === 'post') {
                        unset($body->properties['lock_version'], $body->properties['doctor_remove_ids']);
                    } else {
                        $body->addRequired(['lock_version']);
                    }
                    $operation->requestBodyObject->setContent('application/json', $document->components->addSchema(
                        $operation->method === 'post' ? 'CreateClinicRequest' : 'UpdateClinicRequest', Schema::fromType($body)));
                }
                foreach ($operation->parameters as $parameter) {
                    if ($parameter->name === 'format') {
                        $parameter->setSchema(Schema::fromType((new StringType)->enum(['xlsx', 'pdf'])));
                    }
                }
                $operation->description .= "\nRequires auth:sanctum → active account → api ability, then clinics.view and the operation permission in the SAME active facility. Deactivated accounts lose all tokens (403 ACCOUNT_INACTIVE). Every response is private, no-store. Staff is a global directory; eligible doctors have active staff/type and an explicitly configured staff_types.code. Current intervals are [starts_on, ends_on) in the facility timezone. Edits use lock_version plus doctor_add_ids/doctor_remove_ids, never replacement sync. Doctor/patient counts are distinct. Exports include ALL filtered rows, selected columns, server issuer/number/timezone; caps 1000 clinics / 5000 current links, 422 instead of truncation. Long texts continue in explicit appendices; Cairo is embedded in PDF and named in XLSX. Relationship writes also increment staff.lock_version and lock staff before clinics. Report bytes are never public.";
                $operation->description .= $this->lifecycleDescription();
                if ($operation->method === 'delete') {
                    $operation->addResponse(Response::make(204)->setDescription('Unreferenced clinic deleted and audited. No content.'));
                } elseif (str_contains($route, '/export/') || str_ends_with($route, '/report')) {
                    $response = Response::make(200)->setDescription('Private attachment. Content-Disposition filename and X-Report-Number identify the unique report.');
                    $binary = Schema::fromType((new StringType)->format('binary'));
                    $response->setContent('application/pdf', $binary);
                    if (str_contains($route, '/export/')) {
                        $response->setContent('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $binary);
                    }
                    $operation->addResponse($response);
                } else {
                    $options = str_ends_with($route, 'options/doctors');
                    $doctors = str_ends_with($route, '/doctors');
                    $isList = $route === 'clinics' && $operation->method === 'get';
                    $fields = ['data' => $doctors ? $this->list($doctor) : (str_ends_with($route, '/specialties') ? $this->list($specialty) : ($isList ? $this->list($clinic) : $clinic))];
                    if ($doctors || $isList) {
                        $fields['meta'] = $meta;
                    }
                    if ($options) {
                        $fields['doctor_types_configured'] = new BooleanType;
                        $fields['unavailable'] = $this->unavailableChoices();
                        $this->configureLookup($operation);
                    }
                    if (str_ends_with($route, '/deletion-preview')) {
                        $fields = ['data' => $this->deletionPreviewSchema()];
                    } elseif (str_ends_with($route, '/link-history')) {
                        $fields = ['data' => $this->list($this->historySchema()), 'meta' => $meta];
                    }
                    $operation->addResponse(Response::make($route === 'clinics' && $operation->method === 'post' ? 201 : 200)
                        ->setDescription('Clinic response; fields are limited to this operation.')->setContent('application/json', Schema::fromType($this->object($fields))));
                }
                $errors = [
                    401 => [AuthError::Unauthenticated->value],
                    403 => ['ACCOUNT_INACTIVE', 'MISSING_API_ABILITY', 'CLINIC_ACCESS_DENIED'],
                    404 => ['CLINIC_NOT_FOUND'],
                    409 => ['CLINIC_VERSION_CONFLICT', 'CLINIC_PERIOD_CONFLICT', 'CLINIC_REFERENCED', 'CLINIC_STATE_CONFLICT'],
                    500 => ['CLINICS_UNAVAILABLE'],
                ];
                foreach ($errors as $status => $codes) {
                    $operation->addResponse(Response::make($status)->setDescription(implode(' / ', $codes))
                        ->setContent('application/json', Schema::fromType($this->object(['error' => $this->object(['code' => (new StringType)->enum($codes), 'message' => new StringType])]))));
                }
                if (str_contains($route, '/export/') || str_ends_with($route, '/report')) {
                    // Validation and export-cap errors have different, explicit envelopes.
                    $validationErrors = new ObjectType;
                    $validationErrors->additionalProperties = $this->list(new StringType);
                    $validation = $this->object(['message' => new StringType, 'errors' => $validationErrors]);
                    $limit = $this->object(['error' => $this->object(['code' => (new StringType)->enum(['EXPORT_LIMIT_EXCEEDED']), 'message' => new StringType])]);
                    $operation->addResponse(Response::make(422)->setDescription('Invalid input, or export exceeds safe bounds (no truncation).')->setContent('application/json', Schema::fromType((new AnyOf)->setItems([$validation, $limit]))));
                }
            }
        }
    }
}
