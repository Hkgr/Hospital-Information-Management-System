<?php

namespace App\OpenApi;

use App\Services\Catalog\CatalogBeneficiaries;
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

class CatalogDocumentTransformer extends ClinicDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        $item = $this->object(['id' => new IntegerType, 'kind' => (new StringType)->enum(['service', 'procedure', 'medication']), 'code' => new StringType, 'name_ar' => new StringType,
            'description' => (new StringType)->nullable(true), 'category_id' => (new IntegerType)->nullable(true), 'procedure_type_id' => (new IntegerType)->nullable(true), 'classification_name_ar' => (new StringType)->nullable(true),
            'default_unit' => (new StringType)->nullable(true), 'strength' => (new StringType)->nullable(true), 'dosage_form' => (new StringType)->nullable(true), 'reorder_level' => (new StringType)->nullable(true),
            'is_active' => new BooleanType, 'archived_at' => (new StringType)->nullable(true), 'lock_version' => new IntegerType, 'patient_count' => new IntegerType,
            'patient_count_definition' => (new StringType)->example(CatalogBeneficiaries::DEFINITION)]);
        $meta = $this->object(['page' => new IntegerType, 'per_page' => new IntegerType, 'total' => new IntegerType, 'last_page' => new IntegerType]);
        $caps = $this->object(array_fill_keys(['create', 'update', 'delete', 'export', 'beneficiaries', 'audit'], new BooleanType));
        $choice = $this->object(['id' => new IntegerType, 'name_ar' => new StringType]);
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if ($route !== 'service-catalog' && ! str_starts_with($route, 'service-catalog/')) {
                continue;
            }
            foreach ($path->operations as $operation) {
                if (($route === 'service-catalog' && $operation->method === 'post') || $operation->method === 'put') {
                    $schema = $operation->requestBodyObject->content['application/json'];
                    $template = ($schema instanceof Reference ? $schema->resolve() : $schema)->type->clone();
                    $required = ['facility_id', 'code', 'name_ar', 'is_active'];
                    if ($operation->method === 'post') {
                        $service = $template->clone();
                        unset($service->properties['lock_version'], $service->properties['procedure_type_id']);
                        $service->addProperty('kind', (new StringType)->enum(['service']))->setRequired([...$required, 'kind', 'category_id']);
                        $procedure = $template->clone();
                        unset($procedure->properties['lock_version'], $procedure->properties['category_id']);
                        $procedure->addProperty('kind', (new StringType)->enum(['procedure']))->addProperty('procedure_type_id', (new IntegerType)->nullable(true))->setRequired([...$required, 'kind']);
                        $medication = $template->clone();
                        unset($medication->properties['lock_version'], $medication->properties['procedure_type_id']);
                        $medication->addProperty('kind', (new StringType)->enum(['medication']))->addProperty('category_id', (new IntegerType)->nullable(true))
                            ->addProperty('default_unit', (new StringType)->nullable(true))->addProperty('strength', (new StringType)->nullable(true))
                            ->addProperty('dosage_form', (new StringType)->nullable(true))->addProperty('reorder_level', (new StringType)->nullable(true))
                            ->setRequired([...$required, 'kind']);
                        $operation->requestBodyObject->setContent('application/json', Schema::fromType((new AnyOf)->setItems([$service, $procedure, $medication])));
                    } else {
                        unset($template->properties['kind']);
                        $template->addProperty('procedure_type_id', (new IntegerType)->nullable(true))->setRequired([...$required, 'lock_version']);
                        $operation->requestBodyObject->setContent('application/json', $document->components->addSchema('UpdateCatalogRequest', Schema::fromType($template)));
                    }
                }
                $operation->security = [new SecurityRequirement(['bearerAuth' => []])];
                $operation->description .= '\nRequires Sanctum Bearer with api ability and active account; all responses private, no-store. catalog.view in the selected active facility is always required. Definitions are GLOBAL, separate services/procedures/medications tables; identity is (kind,id), kind is immutable. Codes are manually supplied, unique per kind under MySQL collation, including archived records. No direct clinic definition relationship exists. Write authority additionally requires global_user_roles catalog.directory.create/update/delete, never a facility-only grant. Archive/delete require delete; deactivate/reactivate/restore require update. Restore is inactive, no treatment history changes. All existing-record writes require lock_version; 409 never silently retries. Options always exclude inactive/archived records. The frontend does not grant medical access via catalog.view: beneficiaries additionally needs catalog.beneficiaries; history needs catalog.audit and only exposes selected-facility audit records. No patient detail route exists yet. Export requires catalog.export; ALL matching rows, selected columns and the matching patients table, not current page, maximum 1000 items or 5000 patient rows or 422 without truncation. Classification choices come from existing service_categories (required for services), procedure_types (optional) and medication_categories (optional).';
                $operation->description .= '\nBeneficiary definition: '.CatalogBeneficiaries::DEFINITION;
                $operation->responses = array_values(array_filter($operation->responses ?? [], fn ($response) => (int) ($response instanceof Reference ? $response->resolve() : $response)->code < 200 || (int) ($response instanceof Reference ? $response->resolve() : $response)->code >= 300));
                if ($operation->method === 'delete') {
                    $operation->addResponse(Response::make(204)->setDescription('Hard deleted only after reference recheck; no body.'));
                } elseif (str_contains($route, '/export/') || str_ends_with($route, '/report')) {
                    $response = Response::make(200)->setDescription('Private attachment with Content-Disposition and X-Report-Number.');
                    $response->setContent('application/pdf', Schema::fromType((new StringType)->format('binary')));
                    if (str_contains($route, '/export/')) {
                        $response->setContent('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Schema::fromType((new StringType)->format('binary')));
                    }
                    $operation->addResponse($response);
                } else {
                    $fields = ['data' => $item];
                    if (($route === 'service-catalog' && $operation->method === 'get') || str_ends_with($route, '/options')) {
                        $fields = ['data' => $this->list($item), 'meta' => $meta];
                    }
                    if (($route === 'service-catalog' || $route === 'service-catalog/{kind}/{item}') && $operation->method === 'get') {
                        $fields['capabilities'] = $caps;
                    }
                    if (str_ends_with($route, '/classifications')) {
                        $fields = ['data' => $this->object(['categories' => $this->list($choice), 'procedure_types' => $this->list($choice), 'medication_categories' => $this->list($choice)])];
                    }
                    if (str_ends_with($route, '/context')) {
                        $fields = ['data' => $this->object(['facility' => $this->object(['id' => new IntegerType, 'code' => new StringType, 'name_ar' => new StringType, 'timezone' => new StringType])])];
                        $operation->description .= '\nRequires explicit facility_id selected from the authenticated identity, as in doctors/clinics: URL facility_id takes precedence; when absent the UI uses the first entry with catalog.view in identity order. Validates current active facility and catalog.view; no fallback for an inaccessible ID, no catalog-specific environment configuration. Missing/invalid facility_id returns field validation 422; inaccessible/inactive facility returns 403 without facility data.';
                    }
                    if (str_ends_with($route, '/categories')) {
                        $fields = ['data' => $this->object(['id' => new IntegerType, 'code' => new StringType, 'name_ar' => new StringType, 'is_active' => new BooleanType])];
                        $operation->description .= '\nCategory creation requires GLOBAL catalog.directory.create plus selected-facility catalog.view; facility-only create is insufficient. Duplicate code is a field validation error. No grants are assigned automatically.';
                    }
                    if (str_ends_with($route, '/events')) {
                        $fields = ['data' => $this->list($this->object(['key' => new StringType, 'source' => (new StringType)->enum(['visit_service', 'visit_procedure', 'blood_procedure', 'visit_medication', 'dose_session_item']), 'event_id' => new IntegerType, 'patient_code' => new StringType, 'patient_name' => new StringType, 'performed_on' => (new StringType)->nullable(true), 'visit_no' => (new StringType)->nullable(true)])), 'meta' => $meta, 'totals' => $this->object(['unique_patients' => new IntegerType, 'presentations' => new IntegerType])];
                        $operation->description .= '\nRequires catalog.beneficiaries. One row per eligible source + event id, preserving repeated presentations; performed_on comes from the event, never visit_date or created_at. Search and inclusive from/to date filters, stable server sorting/pagination. Both totals cover ALL matching rows, not the current page. No patient detail links are available.';
                    }
                    if (str_ends_with($route, '/deletion-preview')) {
                        $fields = ['data' => $this->object(['action' => (new StringType)->enum(['delete', 'archive']), 'has_references' => new BooleanType, 'lock_version' => new IntegerType, 'archived' => new BooleanType])];
                    }
                    if (str_ends_with($route, '/beneficiaries')) {
                        $fields = ['data' => $this->list($this->object(['id' => new IntegerType, 'patient_code' => new StringType, 'first_name' => new StringType, 'family_name' => new StringType])), 'meta' => $meta];
                    }
                    if (str_ends_with($route, '/history')) {
                        $fields = ['data' => $this->list($this->object(['id' => new IntegerType, 'event' => new StringType, 'occurred_at' => new StringType, 'old_values' => (new StringType)->nullable(true), 'new_values' => (new StringType)->nullable(true)])), 'meta' => $meta];
                    }
                    $operation->addResponse(Response::make(in_array($route, ['service-catalog', 'service-catalog/categories'], true) && $operation->method === 'post' ? 201 : 200)->setDescription('Catalog response; patient identities require the authorized beneficiaries/events endpoints.')->setContent('application/json', Schema::fromType($this->object($fields))));
                }
                foreach ([401 => ['UNAUTHENTICATED'], 403 => ['ACCOUNT_INACTIVE', 'MISSING_API_ABILITY', 'CATALOG_ACCESS_DENIED', 'CATALOG_DIRECTORY_ACCESS_DENIED'], 404 => ['CATALOG_NOT_FOUND'], 409 => ['CATALOG_VERSION_CONFLICT', 'CATALOG_STATE_CONFLICT', 'CATALOG_REFERENCED'], 500 => ['CATALOG_UNAVAILABLE']] as $status => $codes) {
                    $operation->addResponse(Response::make($status)->setDescription(implode(' / ', $codes))->setContent('application/json', Schema::fromType($this->object(['error' => $this->object(['code' => (new StringType)->enum($codes), 'message' => new StringType])]))));
                }
                $errors = new ObjectType;
                $errors->additionalProperties = $this->list(new StringType);
                $operation->addResponse(Response::make(422)->setDescription('Invalid fields, duplicate code or export limit.')->setContent('application/json', Schema::fromType((new AnyOf)->setItems([
                    $this->object(['message' => new StringType, 'errors' => $errors]), $this->object(['error' => $this->object(['code' => (new StringType)->enum(['EXPORT_LIMIT_EXCEEDED']), 'message' => new StringType])]),
                ]))));
            }
        }
    }
}
