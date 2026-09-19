<?php

namespace App\Services\Catalog;

use App\Exceptions\CatalogException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CatalogQueries
{
    public const COLUMNS = ['number' => 'م', 'code' => 'الكود', 'name_ar' => 'الاسم', 'kind' => 'النوع', 'description' => 'الوصف', 'patient_count' => 'عدد المستفيدين', 'is_active' => 'الحالة'];

    public static function kinds(): array
    {
        return ['service', 'procedure', 'medication'];
    }

    public static function table(string $kind): string
    {
        return match ($kind) {
            'service' => 'services', 'procedure' => 'procedures', 'medication' => 'medications', default => throw new CatalogException('CATALOG_NOT_FOUND', 'النوع غير موجود.', 404)
        };
    }

    public static function classification(string $kind): array
    {
        return match ($kind) {
            'service' => ['service_categories', 'category_id'],
            'procedure' => ['procedure_types', 'procedure_type_id'],
            'medication' => ['medication_categories', 'category_id'],
            default => throw new CatalogException('CATALOG_NOT_FOUND', 'النوع غير موجود.', 404)
        };
    }

    public static function meta($page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }

    public function query(array $facility, array $filters, bool $detail = false): Builder
    {
        $branch = function (string $kind) {
            [$table, $column] = self::classification($kind);
            $extras = match ($kind) {
                'service' => 'd.category_id, NULL as procedure_type_id, NULL as default_unit, NULL as strength, NULL as dosage_form, NULL as reorder_level',
                'procedure' => 'NULL as category_id, d.procedure_type_id, NULL as default_unit, NULL as strength, NULL as dosage_form, NULL as reorder_level',
                'medication' => 'd.category_id, NULL as procedure_type_id, d.default_unit, d.strength, d.dosage_form, d.reorder_level',
            };

            return DB::table(self::table($kind).' as d')
                ->leftJoin($table.' as c', 'c.id', '=', 'd.'.$column)
                ->select('d.id', 'd.code', 'd.name_ar', 'd.description', 'd.is_active', 'd.archived_at', 'd.lock_version', 'c.name_ar as classification_name_ar')
                ->selectRaw('? as kind', [$kind])->selectRaw($extras);
        };
        $counts = app(CatalogBeneficiaries::class)->facts($facility)->select('kind', 'item_id')->selectRaw('COUNT(DISTINCT patient_id) as patient_count')->groupBy('kind', 'item_id');
        $query = DB::query()->fromSub($branch('service')->unionAll($branch('procedure'))->unionAll($branch('medication')), 'catalog')
            ->leftJoinSub($counts, 'counts', fn ($join) => $join->on('counts.kind', '=', 'catalog.kind')->on('counts.item_id', '=', 'catalog.id'))
            ->select('catalog.*')->selectRaw('COALESCE(counts.patient_count, 0) as patient_count');
        if (! $detail) {
            if (($filters['status'] ?? '') === 'archived') {
                $query->whereNotNull('catalog.archived_at');
            } else {
                $query->whereNull('catalog.archived_at');
            }
            if (in_array($filters['status'] ?? '', ['active', 'inactive'], true)) {
                $query->where('catalog.is_active', $filters['status'] === 'active');
            }
        }
        if ($kind = $filters['kind'] ?? null) {
            $query->where('catalog.kind', $kind);
        } else {
            $query->whereIn('catalog.kind', ['service', 'procedure']);
        }
        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($q) => $q->where('catalog.code', 'like', $like)->orWhere('catalog.name_ar', 'like', $like));
        }
        $sort = $filters['sort'] ?? 'code';

        return $query->orderBy($sort === 'patient_count' ? 'patient_count' : 'catalog.'.$sort, $filters['direction'] ?? 'asc')->orderBy('catalog.kind')->orderBy('catalog.id');
    }

    public function row(object $row): array
    {
        return ['id' => (int) $row->id, 'kind' => $row->kind, 'code' => $row->code, 'name_ar' => $row->name_ar, 'description' => $row->description,
            'is_active' => (bool) $row->is_active, 'archived_at' => $row->archived_at, 'lock_version' => (int) $row->lock_version,
            'category_id' => $row->category_id === null ? null : (int) $row->category_id, 'procedure_type_id' => $row->procedure_type_id === null ? null : (int) $row->procedure_type_id,
            'classification_name_ar' => $row->classification_name_ar, 'default_unit' => $row->default_unit, 'strength' => $row->strength, 'dosage_form' => $row->dosage_form,
            'reorder_level' => $row->reorder_level === null ? null : (string) $row->reorder_level,
            'patient_count' => (int) $row->patient_count, 'patient_count_definition' => CatalogBeneficiaries::DEFINITION];
    }

    public function find(array $facility, string $kind, int $id): array
    {
        $row = $this->query($facility, ['kind' => $kind], true)->where('catalog.id', $id)->first();
        if (! $row) {
            throw new CatalogException('CATALOG_NOT_FOUND', 'العنصر غير موجود في الدليل المتاح.', 404);
        }

        return $this->row($row);
    }

    public function listing(array $facility, array $filters): array
    {
        $page = $this->query($facility, $filters)->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $page->getCollection()->map(fn ($row) => $this->row($row))->all(), 'meta' => self::meta($page)];
    }
}
