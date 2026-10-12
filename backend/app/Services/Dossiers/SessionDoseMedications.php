<?php

namespace App\Services\Dossiers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionDoseMedications
{
    public const CATEGORY_CODE = 'AUTO-MCG-002';

    public function eligible(): Builder
    {
        return DB::table('medications as m')->join('medication_categories as c', 'c.id', '=', 'm.category_id')
            ->where('c.code', self::CATEGORY_CODE)->where('c.is_active', true)
            ->where('m.is_active', true)->whereNull('m.archived_at');
    }

    public function selected(int $id): object
    {
        $category = DB::table('medication_categories')->where('code', self::CATEGORY_CODE)->lockForUpdate()->first();
        $medication = DB::table('medications')->where('id', $id)->lockForUpdate()->first();
        if (! $category || ! $category->is_active || ! $medication || $medication->category_id != $category->id || ! $medication->is_active || $medication->archived_at) {
            throw ValidationException::withMessages(['medication_id' => 'اختر دواء فعالًا غير مؤرشف من تصنيف نوعي الموجود في الدليل.']);
        }

        return $medication;
    }
}
