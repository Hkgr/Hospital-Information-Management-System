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

class StockDirectory
{
    public static function tables(): array
    {
        return ['suppliers' => 'medication_suppliers', 'stores' => 'medication_stores'];
    }

    public static function table(string $directory): string
    {
        return self::tables()[$directory] ?? throw new StockException('STOCK_NOT_FOUND', 'النوع غير موجود.', 404);
    }

    public static function entity(string $directory): string
    {
        return $directory === 'stores' ? 'medication_store' : 'medication_supplier';
    }

    public static function fields(string $directory): array
    {
        return $directory === 'stores'
            ? ['code', 'name_ar', 'location', 'is_active']
            : ['code', 'name_ar', 'contact_person', 'phone', 'address_line', 'note', 'is_active'];
    }

    public function listing(array $facility, string $directory, array $filters): array
    {
        $page = $this->query($facility, $directory, $filters)->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $this->present($directory, $page->getCollection()), 'meta' => CatalogQueries::meta($page)];
    }

    public function find(array $facility, string $directory, int $id, bool $includeArchived = true): array
    {
        $query = $this->query($facility, $directory, [], $includeArchived)->where('d.id', $id);
        $row = $query->first();
        if (! $row) {
            throw new StockException('STOCK_NOT_FOUND', 'السجل غير موجود في المنشأة المحددة.', 404);
        }

        return $this->present($directory, collect([$row]))[0];
    }

    public function save(Request $request, array $facility, string $directory, array $input, ?int $id): int
    {
        try {
            return DB::transaction(function () use ($request, $facility, $directory, $input, $id) {
                $old = $id === null ? null : $this->locked($facility, $directory, $id, $input['lock_version']);
                if ($old && $old['archived_at'] !== null) {
                    throw new StockException('STOCK_STATE_CONFLICT', 'استعد السجل المؤرشف قبل تعديله.');
                }
                $fields = Arr::only($input, self::fields($directory));
                $fields['updated_at'] = now();
                $fields['updated_by'] = $request->user()->id;
                if ($id === null) {
                    $id = DB::table(self::table($directory))->insertGetId($fields + [
                        'facility_id' => $facility['id'], 'entered_by' => $request->user()->id,
                        'created_at' => now(), 'lock_version' => 1,
                    ]);
                } else {
                    DB::table(self::table($directory))->where('id', $id)->update($fields + ['lock_version' => $old['lock_version'] + 1]);
                }
                $this->audit($request, $facility, $directory, $id, $old ? 'updated' : 'created', $old);

                return $id;
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'الكود مستخدم في هذه المنشأة.']);
            }
            throw $e;
        }
    }

    public function apply(Request $request, array $facility, string $directory, int $id, int $version, string $action): void
    {
        try {
            DB::transaction(function () use ($request, $facility, $directory, $id, $version, $action) {
                $row = $this->locked($facility, $directory, $id, $version);
                if ($action === 'delete') {
                    if ($this->referenced($directory, $id)) {
                        throw new StockException('STOCK_REFERENCED', 'للسجل استخدامات أو مراجع تاريخية؛ يمكن أرشفته دون حذف التاريخ.');
                    }
                    DB::table(self::table($directory))->where('id', $id)->delete();
                    $this->audit($request, $facility, $directory, $id, 'deleted', $row);

                    return;
                }
                $valid = match ($action) {
                    'archive' => $row['archived_at'] === null,
                    'restore' => $row['archived_at'] !== null,
                    'deactivate' => $row['archived_at'] === null && $row['is_active'],
                    'reactivate' => $row['archived_at'] === null && ! $row['is_active'],
                    default => false,
                };
                if (! $valid) {
                    throw new StockException('STOCK_STATE_CONFLICT', 'تغيّرت حالة السجل؛ أعد تحميل بياناته.');
                }
                DB::table(self::table($directory))->where('id', $id)->update([
                    'is_active' => $action === 'reactivate',
                    'archived_at' => $action === 'archive' ? now() : null,
                    'lock_version' => $row['lock_version'] + 1,
                    'updated_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
                $this->audit($request, $facility, $directory, $id, match ($action) {
                    'archive' => 'archived', 'restore' => 'restored', 'deactivate' => 'deactivated', 'reactivate' => 'reactivated',
                }, $row);
            }, 3);
        } catch (QueryException $e) {
            if ($action === 'delete' && ($e->errorInfo[1] ?? null) === 1451) {
                throw new StockException('STOCK_REFERENCED', 'أضيف مرجع إلى السجل؛ لا يمكن حذفه.');
            }
            throw $e;
        }
    }

    public function preview(array $facility, string $directory, int $id): array
    {
        $row = $this->find($facility, $directory, $id);
        $referenced = $this->referenced($directory, $id);

        return ['action' => $referenced ? 'archive' : 'delete', 'has_references' => $referenced, 'lock_version' => $row['lock_version'], 'archived' => $row['archived_at'] !== null];
    }

    public function referenced(string $directory, int $id): bool
    {
        if ($directory === 'stores') {
            foreach (['medication_batches', 'medication_receipts', 'inventory_transactions', 'stock_issues'] as $table) {
                if (DB::table($table)->where('store_id', $id)->exists()) {
                    return true;
                }
            }

            return false;
        }

        return DB::table('medication_batches')->where('supplier_id', $id)->exists()
            || DB::table('medication_receipts')->where('supplier_id', $id)->exists();
    }

    public function options(array $facility): array
    {
        $active = fn (string $table) => DB::table($table)->where('facility_id', $facility['id'])->whereNull('archived_at')->where('is_active', true)
            ->orderBy('code')->orderBy('id')->get(['id', 'code', 'name_ar']);
        $medications = DB::table('medications')->whereNull('archived_at')->where('is_active', true)->orderBy('code')->orderBy('id')->get(['id', 'code', 'name_ar']);
        $sources = [];
        foreach (OncologyQueries::MEDICATION_SOURCES as $code => $name) {
            $sources[] = ['code' => $code, 'name_ar' => $name];
        }

        return [
            'stores' => $active('medication_stores')->all(),
            'suppliers' => $active('medication_suppliers')->all(),
            'medications' => $medications->all(),
            'medication_sources' => $sources,
        ];
    }

    private function query(array $facility, string $directory, array $filters, bool $includeArchived = false)
    {
        $query = DB::table(self::table($directory).' as d')->where('d.facility_id', $facility['id'])->select('d.*');
        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $query->where(fn ($q) => $q->whereLike('d.code', '%'.$search.'%')->orWhereLike('d.name_ar', '%'.$search.'%'));
        }
        $status = $filters['status'] ?? null;
        if ($status === 'archived') {
            $query->whereNotNull('d.archived_at');
        } elseif (! $includeArchived) {
            $query->whereNull('d.archived_at');
        }
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('d.is_active', $status === 'active');
        }
        $sort = in_array($filters['sort'] ?? 'code', ['code', 'name_ar', 'is_active'], true) ? 'd.'.($filters['sort'] ?? 'code') : 'd.code';

        return $query->orderBy($sort, $filters['direction'] ?? 'asc')->orderBy('d.id');
    }

    private function present(string $directory, $rows): array
    {
        return $rows->map(function ($row) use ($directory) {
            $base = ['id' => (int) $row->id, 'facility_id' => (int) $row->facility_id, 'code' => $row->code, 'name_ar' => $row->name_ar,
                'is_active' => (bool) $row->is_active, 'archived_at' => $row->archived_at, 'lock_version' => (int) $row->lock_version];

            return $directory === 'stores'
                ? $base + ['location' => $row->location]
                : $base + ['contact_person' => $row->contact_person, 'phone' => $row->phone, 'address_line' => $row->address_line, 'note' => $row->note];
        })->all();
    }

    private function locked(array $facility, string $directory, int $id, int $version): array
    {
        $row = DB::table(self::table($directory))->where('facility_id', $facility['id'])->where('id', $id)->lockForUpdate()->first();
        if (! $row) {
            throw new StockException('STOCK_NOT_FOUND', 'السجل غير موجود في المنشأة المحددة.', 404);
        }
        if ((int) $row->lock_version !== $version) {
            throw new StockException('STOCK_VERSION_CONFLICT', 'عدّل مستخدم آخر هذا السجل. أعد تحميل بياناته قبل الحفظ.');
        }

        return (array) $row;
    }

    private function audit(Request $request, array $facility, string $directory, int $id, string $event, ?array $old): void
    {
        $row = DB::table(self::table($directory))->find($id);
        app(ClinicAudit::class)->record($request, $facility['id'], $id, $event, $old, $row ? (array) $row : null, self::entity($directory));
    }
}
