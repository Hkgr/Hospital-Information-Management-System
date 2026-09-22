<?php

namespace App\Services\Directory;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class DirectoryPatientTable
{
    public const TITLE = 'جدول المرضى';

    public static function search(Builder $query, ?string $search): Builder
    {
        $term = trim(preg_replace('/\s+/u', ' ', $search ?? ''));
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(fn ($q) => $q->where('p.patient_code', 'like', $like)->orWhere('p.first_name', 'like', $like)->orWhere('p.family_name', 'like', $like)
            ->orWhereRaw("REGEXP_REPLACE(CONCAT_WS(' ', p.first_name, p.family_name), '[[:space:]]+', ' ') LIKE ?", [$like]));
    }

    public static function present($page, callable $meta): array
    {
        return ['data' => $page->getCollection()->map(fn ($row) => [
            'id' => (int) $row->patient_id, 'patient_code' => $row->patient_code,
            'patient_name' => trim($row->first_name.' '.$row->family_name),
            'visit_count' => (int) $row->visit_count, 'last_visit_on' => $row->last_on,
        ])->all(), 'meta' => $meta($page)];
    }

    public static function report(Collection $patients): array
    {
        return $patients->map(fn ($patient) => [
            'code' => $patient->patient_code, 'name' => trim($patient->first_name.' '.$patient->family_name),
            'visits' => (int) $patient->visit_count, 'last_on' => $patient->last_on,
        ])->values()->all();
    }
}
