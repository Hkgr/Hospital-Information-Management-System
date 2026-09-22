<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\SaveStockDirectoryRequest;
use App\Http\Requests\Stock\SaveStockReceiptRequest;
use App\Http\Requests\Stock\StockQueryRequest;
use App\Http\Requests\Stock\StockVersionRequest;
use App\Services\MedicationStock\StockAccess;
use App\Services\MedicationStock\StockDirectory;
use App\Services\MedicationStock\StockReceipts;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

#[Group('Medication stock')]
class StockController extends Controller
{
    public function __construct(private StockAccess $access, private StockDirectory $directory, private StockReceipts $receipts) {}

    public function options(StockQueryRequest $request): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->directory->options($facility)]);
    }

    public function index(StockQueryRequest $request, string $directory): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json($this->directory->listing($facility, $directory, $request->validated()) + ['capabilities' => $this->access->capabilities($facility)]);
    }

    public function show(StockQueryRequest $request, int $item): JsonResponse
    {
        $directory = (string) $request->route('directory');
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->directory->find($facility, $directory, $item), 'capabilities' => $this->access->capabilities($facility)]);
    }

    public function store(SaveStockDirectoryRequest $request, string $directory): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'suppliers.manage');
        $id = $this->directory->save($request, $facility, $directory, $request->validated(), null);

        return response()->json(['data' => $this->directory->find($facility, $directory, $id)], 201);
    }

    public function update(SaveStockDirectoryRequest $request, int $item): JsonResponse
    {
        $directory = (string) $request->route('directory');
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'suppliers.manage');
        $this->directory->save($request, $facility, $directory, $request->validated(), $item);

        return response()->json(['data' => $this->directory->find($facility, $directory, $item)]);
    }

    public function destroy(StockVersionRequest $request, int $item): Response
    {
        $directory = (string) $request->route('directory');
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'suppliers.manage');
        $this->directory->apply($request, $facility, $directory, $item, $request->integer('lock_version'), 'delete');

        return response()->noContent();
    }

    public function lifecycle(StockVersionRequest $request, int $item): JsonResponse
    {
        $directory = (string) $request->route('directory');
        $action = basename($request->path());
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'suppliers.manage');
        $this->directory->apply($request, $facility, $directory, $item, $request->integer('lock_version'), $action);

        return response()->json(['data' => $this->directory->find($facility, $directory, $item)]);
    }

    public function deletionPreview(StockQueryRequest $request, int $item): JsonResponse
    {
        $directory = (string) $request->route('directory');
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'suppliers.manage');

        return response()->json(['data' => $this->directory->preview($facility, $directory, $item)]);
    }

    public function receipts(StockQueryRequest $request): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json($this->receipts->listing($facility, $request->validated()) + ['capabilities' => $this->access->capabilities($facility)]);
    }

    public function receipt(StockQueryRequest $request, int $receipt): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->receipts->find($facility, $receipt), 'capabilities' => $this->access->capabilities($facility)]);
    }

    public function createReceipt(SaveStockReceiptRequest $request): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'receive');
        $id = $this->receipts->save($request, $facility, $request->validated(), null);

        return response()->json(['data' => $this->receipts->find($facility, $id)], 201);
    }

    public function updateReceipt(SaveStockReceiptRequest $request, int $receipt): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'receive');
        $this->receipts->save($request, $facility, $request->validated(), $receipt);

        return response()->json(['data' => $this->receipts->find($facility, $receipt)]);
    }

    public function confirm(StockVersionRequest $request, int $receipt): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'receive');
        $id = $this->receipts->confirm($request, $facility, $receipt, $request->integer('lock_version'));

        return response()->json(['data' => $this->receipts->find($facility, $id)]);
    }
}
