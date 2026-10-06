<?php

namespace App\Services\Catalog;

use App\Exceptions\CatalogException;
use App\Services\Clinics\ClinicAudit;
use App\Services\Directory\CreationRequests;
use App\Services\Directory\IssuedCodes;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogWriter
{
    public function createCategory(Request $request, array $facility, array $data): object
    {
        $kind = $data['kind'] ?? 'service';
        app(CatalogAccess::class)->directory($request->user(), $facility, 'create', $kind);
        [$table] = CatalogQueries::classification($kind);
        $entity = match ($kind) {
            'service' => 'service_category', 'procedure' => 'procedure_type', 'medication' => 'medication_category',
        };
        try {
            $id = app(CreationRequests::class)->save($request, $facility, $data, 'classification:'.$kind, null, function () use ($request, $facility, $data, $table, $entity, $kind) {
                $fields = array_intersect_key($data, array_flip(['name_ar', 'is_active']));
                $fields['code'] = app(IssuedCodes::class)->classification($kind);
                $id = DB::table($table)->insertGetId($fields + ['created_at' => now(), 'updated_at' => now()]);
                app(ClinicAudit::class)->record($request, $facility['id'], $id, 'created', null, $fields, $entity);

                return $id;
            });
            $row = DB::table($table)->find($id, ['id', 'code', 'name_ar', 'is_active']);
            abort_unless($row, 404);
            $row->is_active = (bool) $row->is_active;

            return $row;
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'تعذّر إصدار رمز فريد للفئة؛ أعد المحاولة بالطلب نفسه.']);
            }
            throw $exception;
        }
    }

    public function references(string $kind, int $id): bool
    {
        $tables = match ($kind) {
            'service' => ['visit_services', 'report_metric_catalog_items'],
            'procedure' => ['visit_procedures', 'blood_recipient_procedures', 'report_metric_catalog_items'],
            'medication' => ['visit_medications', 'visit_prescription_items', 'dose_session_items', 'report_metric_catalog_items', 'medication_batches', 'inventory_transactions', 'medication_receipt_items', 'stock_issues'],
            default => throw new CatalogException('CATALOG_NOT_FOUND', 'النوع غير موجود.', 404),
        };
        foreach ($tables as $table) {
            if (DB::table($table)->where($kind.'_id', $id)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function locked(string $kind, int $id, int $version): object
    {
        $row = DB::table(CatalogQueries::table($kind))->where('id', $id)->lockForUpdate()->first();
        if (! $row) {
            throw new CatalogException('CATALOG_NOT_FOUND', 'العنصر غير موجود في الدليل المتاح.', 404);
        }
        if ((int) $row->lock_version !== $version) {
            throw new CatalogException('CATALOG_VERSION_CONFLICT', 'عدّل مستخدم آخر هذا العنصر. اجلب أحدث نسخة وراجع مسودتك قبل الحفظ.');
        }

        return $row;
    }

    private function snapshot(object $row): array
    {
        return array_intersect_key((array) $row, array_flip(['code', 'name_ar', 'description', 'is_active', 'archived_at', 'lock_version', 'category_id', 'procedure_type_id', 'default_unit', 'strength', 'dosage_form', 'reorder_level']));
    }

    private function audit(Request $request, array $facility, string $kind, int $id, string $event, ?array $old): void
    {
        $row = DB::table(CatalogQueries::table($kind))->find($id);
        app(ClinicAudit::class)->record($request, $facility['id'], $id, $event, $old, $row ? $this->snapshot($row) : null, $kind);
    }

    public function save(Request $request, array $facility, string $kind, array $data, ?int $id): int
    {
        app(CatalogAccess::class)->directory($request->user(), $facility, $id ? 'update' : 'create', $kind);
        try {
            return app(CreationRequests::class)->save($request, $facility, $data, 'catalog:'.$kind, $id, function () use ($request, $facility, $kind, $data, $id) {
                $row = $id ? $this->locked($kind, $id, $data['lock_version']) : null;
                if ($row && array_key_exists('is_active', $data) && (bool) $row->is_active !== (bool) $data['is_active']) {
                    app(CatalogAccess::class)->directory($request->user(), $facility, $data['is_active'] ? 'reactivate' : 'deactivate');
                }
                if ($row?->archived_at) {
                    throw new CatalogException('CATALOG_STATE_CONFLICT', 'استعد العنصر المؤرشف أولًا قبل تعديله.');
                }
                [$classTable, $relation] = CatalogQueries::classification($kind);
                $value = $data[$relation] ?? null;
                if ($value && (! $row || $value != $row->$relation)) {
                    $valid = DB::table($classTable)->where('id', $value)->where('is_active', true)->sharedLock()->exists();
                    if (! $valid) {
                        throw ValidationException::withMessages([$relation => 'التصنيف غير موجود أو غير فعال.']);
                    }
                }
                $keys = ['code', 'name_ar', 'description', 'is_active', $relation];
                if ($kind === 'medication') {
                    $keys = [...$keys, 'default_unit', 'strength', 'dosage_form', 'reorder_level'];
                }
                $fields = array_intersect_key($data, array_flip($keys));
                unset($fields['code']);
                $fields['code'] = $row?->code ?? app(IssuedCodes::class)->catalog($kind);
                $fields['description'] = $data['description'] ?? null;
                $fields[$relation] = $value;
                $fields['updated_at'] = now();
                if ($row) {
                    $fields['lock_version'] = $row->lock_version + 1;
                    DB::table(CatalogQueries::table($kind))->where('id', $id)->update($fields);
                } else {
                    $id = DB::table(CatalogQueries::table($kind))->insertGetId($fields + ['created_at' => now(), 'lock_version' => 1]);
                }
                $this->audit($request, $facility, $kind, $id, $row ? 'updated' : 'created', $row ? $this->snapshot($row) : null);

                return $id;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'تعذّر إصدار كود فريد؛ أعد المحاولة بالطلب نفسه.']);
            }
            throw $exception;
        }
    }

    public function apply(Request $request, array $facility, string $kind, int $id, int $version, string $action): void
    {
        app(CatalogAccess::class)->directory($request->user(), $facility, $action === 'delete' ? 'destroy' : $action);
        try {
            DB::transaction(function () use ($request, $facility, $kind, $id, $version, $action) {
                $row = $this->locked($kind, $id, $version);
                $old = $this->snapshot($row);
                if ($action === 'delete') {
                    if ($this->references($kind, $id)) {
                        throw new CatalogException('CATALOG_REFERENCED', 'للعنصر استخدامات أو مراجع تاريخية؛ يمكن أرشفته دون حذف التاريخ.');
                    }
                    DB::table(CatalogQueries::table($kind))->where('id', $id)->delete();
                    $this->audit($request, $facility, $kind, $id, 'deleted', $old);

                    return;
                }
                $valid = match ($action) {
                    'archive' => $row->archived_at === null,
                    'restore' => $row->archived_at !== null,
                    'deactivate' => $row->archived_at === null && $row->is_active,
                    'reactivate' => $row->archived_at === null && ! $row->is_active,
                    default => false,
                };
                if (! $valid) {
                    throw new CatalogException('CATALOG_STATE_CONFLICT', 'تغيّرت حالة العنصر؛ أعد تحميل بياناته.');
                }
                DB::table(CatalogQueries::table($kind))->where('id', $id)->update(['is_active' => $action === 'reactivate',
                    'archived_at' => $action === 'archive' ? now() : null, 'lock_version' => $row->lock_version + 1, 'updated_at' => now()]);
                $this->audit($request, $facility, $kind, $id, match ($action) {
                    'archive' => 'archived', 'restore' => 'restored', 'deactivate' => 'deactivated', 'reactivate' => 'reactivated'
                }, $old);
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1451) {
                throw new CatalogException('CATALOG_REFERENCED', 'أضيف مرجع إلى العنصر؛ لا يمكن حذفه.');
            }
            throw $exception;
        }
    }
}
