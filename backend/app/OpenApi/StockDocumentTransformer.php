<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class StockDocumentTransformer extends ClinicDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        $choice = $this->object(['id' => new IntegerType, 'code' => new StringType, 'name_ar' => new StringType]);
        $supplier = $this->object(['id' => new IntegerType, 'facility_id' => new IntegerType, 'code' => new StringType, 'name_ar' => new StringType,
            'contact_person' => (new StringType)->nullable(true), 'phone' => (new StringType)->nullable(true),
            'address_line' => (new StringType)->nullable(true), 'note' => (new StringType)->nullable(true),
            'is_active' => new BooleanType, 'archived_at' => (new StringType)->nullable(true), 'lock_version' => new IntegerType]);
        $store = $this->object(['id' => new IntegerType, 'facility_id' => new IntegerType, 'code' => new StringType, 'name_ar' => new StringType,
            'location' => (new StringType)->nullable(true), 'is_active' => new BooleanType, 'archived_at' => (new StringType)->nullable(true), 'lock_version' => new IntegerType]);
        $item = $this->object(['id' => new IntegerType, 'medication_id' => new IntegerType, 'medication_code' => (new StringType)->nullable(true),
            'medication_name_ar' => (new StringType)->nullable(true), 'batch_number' => new StringType, 'expiry_date' => new StringType,
            'manufactured_on' => (new StringType)->nullable(true), 'quantity' => new StringType, 'free_quantity' => new StringType,
            'unit_cost' => (new StringType)->nullable(true), 'note' => (new StringType)->nullable(true), 'batch_id' => (new IntegerType)->nullable(true)]);
        $receipt = $this->object(['id' => new IntegerType, 'facility_id' => new IntegerType, 'store_id' => new IntegerType, 'store_name_ar' => (new StringType)->nullable(true),
            'receipt_no' => new StringType, 'supplier_id' => (new IntegerType)->nullable(true), 'supplier_name_ar' => (new StringType)->nullable(true),
            'funding_source_id' => new IntegerType, 'funding_name_ar' => (new StringType)->nullable(true), 'received_on' => new StringType,
            'invoice_number' => (new StringType)->nullable(true), 'status' => (new StringType)->enum(['draft', 'confirmed', 'cancelled']),
            'note' => (new StringType)->nullable(true), 'confirmed_at' => (new StringType)->nullable(true), 'lock_version' => new IntegerType,
            'items' => $this->list($item)]);
        $receiptList = $this->object(['id' => new IntegerType, 'facility_id' => new IntegerType, 'store_id' => new IntegerType, 'store_name_ar' => (new StringType)->nullable(true),
            'receipt_no' => new StringType, 'supplier_id' => (new IntegerType)->nullable(true), 'supplier_name_ar' => (new StringType)->nullable(true),
            'funding_source_id' => new IntegerType, 'funding_name_ar' => (new StringType)->nullable(true), 'received_on' => new StringType,
            'invoice_number' => (new StringType)->nullable(true), 'status' => (new StringType)->enum(['draft', 'confirmed', 'cancelled']),
            'note' => (new StringType)->nullable(true), 'confirmed_at' => (new StringType)->nullable(true), 'lock_version' => new IntegerType]);
        $caps = $this->object(array_fill_keys(['manage', 'receive', 'adjust', 'issue', 'return', 'export'], new BooleanType));
        $meta = $this->object(['page' => new IntegerType, 'per_page' => new IntegerType, 'total' => new IntegerType, 'last_page' => new IntegerType]);
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if ($route !== 'stock' && ! str_starts_with($route, 'stock/')) {
                continue;
            }
            foreach ($path->operations as $operation) {
                $operation->security = [new SecurityRequirement(['bearerAuth' => []])];
                $operation->description .= "\nRequires Sanctum Bearer with api ability and active account; all responses private, no-store. stock.view in the selected active facility is always required, paired with stock.{action}. Suppliers and stores are facility-scoped directories managed with stock.suppliers.manage. Receipts are created as draft and confirmed with stock.receive; confirmation is idempotent, writes batches and one receipt/in ledger row per item for quantity+free_quantity, and never edits a confirmed receipt. request_id is client-supplied. Cross-facility ids return 404.";
                $operation->responses = array_values(array_filter($operation->responses ?? [], fn ($response) => (int) ($response instanceof Reference ? $response->resolve() : $response)->code < 200 || (int) ($response instanceof Reference ? $response->resolve() : $response)->code >= 300));
                if ($operation->method === 'delete') {
                    $operation->addResponse(Response::make(204)->setDescription('Unreferenced directory row deleted.'));
                } else {
                    $fields = ['data' => $supplier];
                    if (str_contains($route, '/stores')) {
                        $fields = ['data' => $store];
                    }
                    if (str_contains($route, '/receipts')) {
                        $fields = ['data' => str_ends_with($route, '/receipts') && $operation->method === 'get' ? $this->list($receiptList) : $receipt];
                    }
                    if (str_ends_with($route, '/options')) {
                        $fields = ['data' => $this->object(['stores' => $this->list($choice), 'suppliers' => $this->list($choice), 'medications' => $this->list($choice), 'funding_sources' => $this->list($choice)])];
                    }
                    if (str_ends_with($route, '/deletion-preview')) {
                        $fields = ['data' => $this->object(['action' => (new StringType)->enum(['delete', 'archive']), 'has_references' => new BooleanType, 'lock_version' => new IntegerType, 'archived' => new BooleanType])];
                    }
                    $isList = $operation->method === 'get' && (preg_match('#^stock/(suppliers|stores|receipts)$#', $route) === 1);
                    if ($isList) {
                        $fields = ['data' => str_contains($route, 'stores') ? $this->list($store) : (str_contains($route, 'receipts') ? $this->list($receiptList) : $this->list($supplier)), 'meta' => $meta, 'capabilities' => $caps];
                    } elseif ($operation->method === 'get' && preg_match('#^stock/(suppliers|stores)/\d+#', $route) !== 1 && str_contains($route, '{')) {
                        $fields['capabilities'] = $caps;
                    }
                    if ($operation->method === 'get' && (str_contains($route, '{item}') || str_contains($route, '{receipt}'))) {
                        $fields['capabilities'] = $caps;
                    }
                    $operation->addResponse(Response::make($operation->method === 'post' && ! str_ends_with($route, '/confirm') && ! str_contains($route, '/archive') && ! str_contains($route, '/restore') && ! str_contains($route, '/deactiv') && ! str_contains($route, '/reactivate') ? 201 : 200)
                        ->setDescription('Stock response.')->setContent('application/json', Schema::fromType($this->object($fields))));
                }
                foreach ([401 => ['UNAUTHENTICATED'], 403 => ['ACCOUNT_INACTIVE', 'MISSING_API_ABILITY', 'STOCK_ACCESS_DENIED'], 404 => ['STOCK_NOT_FOUND'], 409 => ['STOCK_VERSION_CONFLICT', 'STOCK_STATE_CONFLICT', 'STOCK_REFERENCED', 'STOCK_REQUEST_CONFLICT', 'STOCK_BATCH_FUNDING_CONFLICT'], 500 => ['STOCK_UNAVAILABLE']] as $status => $codes) {
                    $operation->addResponse(Response::make($status)->setDescription(implode(' / ', $codes))
                        ->setContent('application/json', Schema::fromType($this->object(['error' => $this->object(['code' => (new StringType)->enum($codes), 'message' => new StringType])]))));
                }
            }
        }
    }
}
