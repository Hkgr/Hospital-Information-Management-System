<?php

namespace App\Services\Support;

use App\Exceptions\DossierException;
use Illuminate\Support\Facades\DB;

class PeriodResolver
{
    public function resolve(int $facilityId, ?string $eventDate): ?int
    {
        if ($eventDate === null || $eventDate === '') {
            return null;
        }
        $ids = DB::table('reporting_periods')->where('facility_id', $facilityId)
            ->where('starts_on', '<=', $eventDate)->where('ends_on', '>=', $eventDate)->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }
        if ($ids->count() > 1) {
            throw new DossierException('PERIOD_AMBIGUOUS', 'تتداخل أكثر من فترة تقريرية مع هذا التاريخ. راجع إعداد الفترات.', 409);
        }

        return (int) $ids->first();
    }
}
