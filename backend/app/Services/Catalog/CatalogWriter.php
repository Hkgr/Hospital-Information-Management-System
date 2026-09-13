<?php

namespace App\Services\Catalog;

use App\Exceptions\CatalogException;
use App\Services\Clinics\ClinicAudit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogWriter
{
    public function createCategory(Request $request, array $facility, array $data): object
    {
        app(CatalogAccess::class)->directory($request->user(), $facility, 'create');
        try {
            return DB::transaction(function () use ($request, $facility, $data) {
                $fields = array_intersect_key($data, array_flip(['code', 'name_ar', 'is_active']));
                $id = DB::table('service_categories')->insertGetId($fields + ['created_at' => now(), 'updated_at' => now()]);
                app(ClinicAudit::class)->record($request, $facility['id'], $id, 'created', null, $fields, 'service_category');
                $row = DB::table('service_categories')->find($id, ['id', 'code', 'name_ar', 'is_active']);
                $row->is_active = (bool) $row->is_active;

                return $row;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'رمز الفئة مستخدم بالفعل؛ اختر رمزًا آخر.']);
            }
            throw $exception;
        }
    }

    public function references(string $kind, int $id): bool
    {
        $tables = $kind === 'service' ? ['visit_services', 'report_metric_catalog_items'] : ['visit_procedures', 'blood_recipient_procedures', 'report_metric_catalog_items'];
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
        return array_intersect_key((array) $row, array_flip(['code', 'name_ar', 'description', 'is_active', 'archived_at', 'lock_version', 'category_id', 'procedure_type_id']));
    }

    private function audit(Request $request, array $facility, string $kind, int $id, string $event, ?array $old): void
    {
        $row = DB::table(CatalogQueries::table($kind))->find($id);
        app(ClinicAudit::class)->record($request, $facility['id'], $id, $event, $old, $row ? $this->snapshot($row) : null, $kind);
    }

    public function save(Request $request, array $facility, string $kind, array $data, ?int $id): int
    {
        app(CatalogAccess::class)->directory($request->user(), $facility, $id ? 'update' : 'create');
        try {
            return DB::transaction(function () use ($request, $facility, $kind, $data, $id) {
                $row = $id ? $this->locked($kind, $id, $data['lock_version']) : null;
                if ($row?->archived_at) {
                    throw new CatalogException('CATALOG_STATE_CONFLICT', 'استعد العنصر المؤرشف أولًا قبل تعديله.');
                }
                $relation = $kind === 'service' ? 'category_id' : 'procedure_type_id';
                $value = $data[$relation] ?? null;
                if ($value && (! $row || $value != $row->$relation)) {
                    $valid = DB::table($kind === 'service' ? 'service_categories' : 'procedure_types')->where('id', $value)->where('is_active', true)->sharedLock()->exists();
                    if (! $valid) {
                        throw ValidationException::withMessages([$relation => 'التصنيف غير موجود أو غير فعال.']);
                    }
                }
                $fields = array_intersect_key($data, array_flip(['code', 'name_ar', 'description', 'is_active', $relation]));
                $fields['description'] = $data['description'] ?? null;
                $fields[$relation] = $value;
                $fields['updated_at'] = now();
                if ($row) {
                    $fields['lock_version'] = $row->lock_version + 1;
                    DB::table(CatalogQueries::table($kind))->where('id', $id)->update($fields);
                } else {
                    $id = DB::table(CatalogQueries::table($kind))->insertGetId($fields + ['created_at' => now()]);
                }
                $this->audit($request, $facility, $kind, $id, $row ? 'updated' : 'created', $row ? $this->snapshot($row) : null);

                return $id;
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'الكود مستخدم بالفعل ضمن هذا النوع؛ اختر كودًا آخر.']);
            }
            throw $exception;
        }
    }

    public function apply(Request $request, array $facility, string $kind, int $id, int $version, string $action): void
    {
        app(CatalogAccess::class)->directory($request->user(), $facility, in_array($action, ['delete', 'archive'], true) ? 'delete' : 'update');
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
