<?php

namespace App\Services\MedicationStock;

use App\Exceptions\StockException;
use App\Services\Catalog\CatalogQueries;
use App\Services\Clinics\ClinicAudit;
use App\Services\Dossiers\OncologyQueries;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockReceipts
{
    public function once(Request $request, array $facility, array $data, string $operation, callable $write): int
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };
        $fingerprint = hash('sha256', $operation.json_encode($canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $facility, $data, $fingerprint, $write) {
            $key = ['facility_id' => $facility['id'], 'user_id' => $request->user()->id, 'request_id' => $data['request_id']];
            DB::table('stock_requests')->insertOrIgnore($key + ['fingerprint' => $fingerprint, 'created_at' => now()]);
            $entry = DB::table('stock_requests')->where($key)->lockForUpdate()->first();
            if ($entry->fingerprint !== $fingerprint) {
                throw new StockException('STOCK_REQUEST_CONFLICT', 'استُخدم معرّف الحفظ لمحتوى مختلف. راجع المسودة وحاول بطلب جديد.');
            }
            if ($entry->entity_id) {
                return (int) $entry->entity_id;
            }
            $id = $write();
            DB::table('stock_requests')->where('id', $entry->id)->update(['entity_id' => $id]);

            return $id;
        }, 3);
    }

    public function listing(array $facility, array $filters): array
    {
        $query = DB::table('medication_receipts as r')->where('r.facility_id', $facility['id'])
            ->leftJoin('medication_stores as st', 'st.id', '=', 'r.store_id')
            ->leftJoin('medication_suppliers as su', 'su.id', '=', 'r.supplier_id')
            ->select('r.*', 'st.name_ar as store_name_ar', 'su.name_ar as supplier_name_ar');
        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $query->where(fn ($q) => $q->whereLike('r.receipt_no', '%'.$search.'%')->orWhereLike('r.invoice_number', '%'.$search.'%'));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('r.status', $status);
        }
        if ($store = $filters['store_id'] ?? null) {
            $query->where('r.store_id', $store);
        }
        $page = $query->orderByDesc('r.received_on')->orderByDesc('r.id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $page->getCollection()->map(fn ($row) => $this->row($row, false))->all(), 'meta' => CatalogQueries::meta($page)];
    }

    public function find(array $facility, int $id, bool $lock = false): array
    {
        $query = DB::table('medication_receipts as r')->where('r.facility_id', $facility['id'])->where('r.id', $id)
            ->leftJoin('medication_stores as st', 'st.id', '=', 'r.store_id')
            ->leftJoin('medication_suppliers as su', 'su.id', '=', 'r.supplier_id')
            ->select('r.*', 'st.name_ar as store_name_ar', 'su.name_ar as supplier_name_ar');
        $row = ($lock ? DB::table('medication_receipts')->where('facility_id', $facility['id'])->where('id', $id)->lockForUpdate()->first() : $query->first());
        if (! $row) {
            throw new StockException('STOCK_NOT_FOUND', 'إذن الاستلام غير موجود في المنشأة المحددة.', 404);
        }
        if ($lock) {
            $row = $query->first();
        }
        $items = DB::table('medication_receipt_items as i')->leftJoin('medications as m', 'm.id', '=', 'i.medication_id')
            ->where('i.receipt_id', $id)->orderBy('i.id')
            ->get(['i.*', 'm.code as medication_code', 'm.name_ar as medication_name_ar']);

        return $this->row($row, true, $items);
    }

    public function save(Request $request, array $facility, array $input, ?int $id): int
    {
        $write = function () use ($request, $facility, $input, $id) {
            try {
                return DB::transaction(function () use ($request, $facility, $input, $id) {
                    $old = $id ? $this->locked($facility, $id, $input['lock_version']) : null;
                    if ($old && $old['status'] !== 'draft') {
                        throw new StockException('STOCK_STATE_CONFLICT', 'لا يُعدَّل إلا إذن استلام مسودة.');
                    }
                    $this->requireStore($facility, (int) $input['store_id']);
                    $this->requireSupplier($facility, $input['supplier_id'] ?? null);
                    $this->requireSource($input['medication_source']);
                    $fields = Arr::only($input, ['store_id', 'receipt_no', 'supplier_id', 'medication_source', 'received_on', 'invoice_number', 'note']);
                    $fields['supplier_id'] = $input['supplier_id'] ?? null;
                    $fields['invoice_number'] = $input['invoice_number'] ?? null;
                    $fields['note'] = $input['note'] ?? null;
                    $fields['updated_at'] = now();
                    $fields['updated_by'] = $request->user()->id;
                    if ($id === null) {
                        $id = DB::table('medication_receipts')->insertGetId($fields + [
                            'facility_id' => $facility['id'], 'status' => 'draft',
                            'client_request_id' => $input['request_id'],
                            'entered_by' => $request->user()->id, 'created_at' => now(), 'lock_version' => 1,
                        ]);
                    } else {
                        DB::table('medication_receipts')->where('id', $id)->update($fields + ['lock_version' => $old['lock_version'] + 1]);
                        DB::table('medication_receipt_items')->where('receipt_id', $id)->delete();
                    }
                    $this->writeItems($request, $facility, $id, $input['items'] ?? []);
                    app(ClinicAudit::class)->record($request, $facility['id'], $id, $old ? 'updated' : 'created', $old, Arr::only($this->find($facility, $id), ['receipt_no', 'status', 'store_id', 'supplier_id', 'medication_source', 'received_on']), 'medication_receipt');

                    return $id;
                }, 3);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw ValidationException::withMessages(['receipt_no' => 'رقم الإذن مستخدم في هذه المنشأة.']);
                }
                throw $e;
            }
        };

        return $id === null
            ? $this->once($request, $facility, $input, 'receipt:new', $write)
            : $write();
    }

    public function confirm(Request $request, array $facility, int $id, int $version): int
    {
        return DB::transaction(function () use ($request, $facility, $id, $version) {
            $locked = DB::table('medication_receipts')->where('facility_id', $facility['id'])->where('id', $id)->lockForUpdate()->first();
            if (! $locked) {
                throw new StockException('STOCK_NOT_FOUND', 'إذن الاستلام غير موجود في المنشأة المحددة.', 404);
            }
            if ($locked->status === 'confirmed') {
                return $id;
            }
            if ((int) $locked->lock_version !== $version) {
                throw new StockException('STOCK_VERSION_CONFLICT', 'عدّل مستخدم آخر هذا الإذن. أعد تحميل بياناته قبل الحفظ.');
            }
            $receipt = (array) $locked;
            if ($receipt['status'] !== 'draft') {
                throw new StockException('STOCK_STATE_CONFLICT', 'لا يُؤكَّد إلا إذن استلام مسودة.');
            }
            $items = DB::table('medication_receipt_items')->where('receipt_id', $id)->orderBy('id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'لا يمكن تأكيد إذن بلا بنود.']);
            }
            foreach ($items as $item) {
                if ($item->expiry_date <= $receipt['received_on']) {
                    throw ValidationException::withMessages(['items' => 'تاريخ انتهاء الصلاحية يجب أن يكون بعد تاريخ الاستلام.']);
                }
                $quantity = bcadd((string) $item->quantity, (string) $item->free_quantity, 4);
                $batch = DB::table('medication_batches')->where([
                    'facility_id' => $facility['id'], 'store_id' => $receipt['store_id'], 'medication_id' => $item->medication_id,
                    'batch_number' => $item->batch_number, 'expiry_date' => $item->expiry_date,
                ])->lockForUpdate()->first();
                if ($batch) {
                    if ($batch->medication_source !== $receipt['medication_source']) {
                        throw new StockException('STOCK_BATCH_FUNDING_CONFLICT', 'دفعة موجودة بجهة تمويل مختلفة؛ صحّح الإذن قبل التأكيد.');
                    }
                    DB::table('medication_batches')->where('id', $batch->id)->update([
                        'received_quantity' => bcadd((string) $batch->received_quantity, $quantity, 4),
                        'lock_version' => $batch->lock_version + 1, 'updated_by' => $request->user()->id, 'updated_at' => now(),
                    ]);
                    $batchId = (int) $batch->id;
                } else {
                    $batchId = DB::table('medication_batches')->insertGetId([
                        'facility_id' => $facility['id'], 'store_id' => $receipt['store_id'], 'medication_id' => $item->medication_id,
                        'batch_number' => $item->batch_number, 'expiry_date' => $item->expiry_date, 'manufactured_on' => $item->manufactured_on,
                        'received_on' => $receipt['received_on'], 'supplier_id' => $receipt['supplier_id'],
                        'medication_source' => $receipt['medication_source'], 'unit_cost' => $item->unit_cost,
                        'received_quantity' => $quantity, 'status' => 'active', 'entered_by' => $request->user()->id,
                        'created_at' => now(), 'updated_at' => now(), 'lock_version' => 1,
                    ]);
                }
                DB::table('medication_receipt_items')->where('id', $item->id)->update(['batch_id' => $batchId, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
                DB::table('inventory_transactions')->insert([
                    'facility_id' => $facility['id'], 'store_id' => $receipt['store_id'], 'medication_id' => $item->medication_id,
                    'batch_id' => $batchId, 'transaction_type' => 'receipt', 'quantity' => $quantity, 'direction' => 'in',
                    'occurred_on' => $receipt['received_on'], 'reference_type' => 'medication_receipt', 'reference_id' => $id,
                    'client_request_id' => $this->itemRequestId($receipt['client_request_id'], (int) $item->id),
                    'entered_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('medication_receipts')->where('id', $id)->update([
                'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => $request->user()->id,
                'lock_version' => $receipt['lock_version'] + 1, 'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            app(ClinicAudit::class)->record($request, $facility['id'], $id, 'confirmed', ['status' => 'draft'], ['status' => 'confirmed'], 'medication_receipt');

            return $id;
        }, 3);
    }

    private function writeItems(Request $request, array $facility, int $receiptId, array $items): void
    {
        foreach ($items as $item) {
            if (! DB::table('medications')->where('id', $item['medication_id'])->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['items' => 'اختر دواءً غير مؤرشف.']);
            }
            DB::table('medication_receipt_items')->insert([
                'receipt_id' => $receiptId, 'facility_id' => $facility['id'], 'medication_id' => $item['medication_id'],
                'batch_number' => $item['batch_number'], 'expiry_date' => $item['expiry_date'],
                'manufactured_on' => $item['manufactured_on'] ?? null, 'quantity' => $item['quantity'],
                'free_quantity' => $item['free_quantity'] ?? 0, 'unit_cost' => $item['unit_cost'] ?? null,
                'note' => $item['note'] ?? null, 'entered_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function requireStore(array $facility, int $id): void
    {
        if (! DB::table('medication_stores')->where('facility_id', $facility['id'])->where('id', $id)->whereNull('archived_at')->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['store_id' => 'اختر مستودعًا فعالًا في المنشأة المحددة.']);
        }
    }

    private function requireSupplier(array $facility, ?int $id): void
    {
        if ($id !== null && ! DB::table('medication_suppliers')->where('facility_id', $facility['id'])->where('id', $id)->whereNull('archived_at')->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'اختر موردًا فعالًا في المنشأة المحددة.']);
        }
    }

    private function requireSource(string $code): void
    {
        if (! array_key_exists($code, OncologyQueries::MEDICATION_SOURCES)) {
            throw ValidationException::withMessages(['medication_source' => 'اختر مصدر تمويل من مصادر الدواء المعتمدة.']);
        }
    }

    private function locked(array $facility, int $id, int $version): array
    {
        $row = DB::table('medication_receipts')->where('facility_id', $facility['id'])->where('id', $id)->lockForUpdate()->first();
        if (! $row) {
            throw new StockException('STOCK_NOT_FOUND', 'إذن الاستلام غير موجود في المنشأة المحددة.', 404);
        }
        if ((int) $row->lock_version !== $version) {
            throw new StockException('STOCK_VERSION_CONFLICT', 'عدّل مستخدم آخر هذا الإذن. أعد تحميل بياناته قبل الحفظ.');
        }

        return (array) $row;
    }

    private function row(object $row, bool $detail, $items = null): array
    {
        $data = [
            'id' => (int) $row->id, 'facility_id' => (int) $row->facility_id, 'store_id' => (int) $row->store_id,
            'store_name_ar' => $row->store_name_ar, 'receipt_no' => $row->receipt_no,
            'supplier_id' => $row->supplier_id ? (int) $row->supplier_id : null, 'supplier_name_ar' => $row->supplier_name_ar,
            'medication_source' => $row->medication_source, 'funding_name_ar' => OncologyQueries::MEDICATION_SOURCES[$row->medication_source] ?? null,
            'received_on' => $row->received_on, 'invoice_number' => $row->invoice_number, 'status' => $row->status,
            'note' => $row->note, 'confirmed_at' => $row->confirmed_at, 'lock_version' => (int) $row->lock_version,
        ];
        if ($detail) {
            $data['items'] = $items->map(fn ($item) => [
                'id' => (int) $item->id, 'medication_id' => (int) $item->medication_id, 'medication_code' => $item->medication_code,
                'medication_name_ar' => $item->medication_name_ar, 'batch_number' => $item->batch_number,
                'expiry_date' => $item->expiry_date, 'manufactured_on' => $item->manufactured_on,
                'quantity' => $item->quantity, 'free_quantity' => $item->free_quantity, 'unit_cost' => $item->unit_cost,
                'note' => $item->note, 'batch_id' => $item->batch_id ? (int) $item->batch_id : null,
            ])->all();
        }

        return $data;
    }

    private function itemRequestId(string $receiptRequest, int $itemId): string
    {
        $hex = hash('sha256', $receiptRequest.'item'.$itemId);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
