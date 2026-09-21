<?php

namespace App\Console\Commands;

use App\Services\Periods\PeriodWriter;
use App\Services\Support\PeriodResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillReportingPeriods extends Command
{
    protected $signature = 'periods:backfill {--apply}';

    protected $description = 'Stamp reporting_period_id on rows whose date matches an existing period; preview by default';

    public function handle(PeriodResolver $resolver): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];
        foreach (PeriodWriter::TABLES as $table => $date) {
            $stamped = 0;
            $left = 0;
            $targets = DB::table($table)->whereNull('reporting_period_id')->whereNotNull($date)->get(['id', 'facility_id', $date]);
            $write = function () use ($targets, $resolver, $date, $table, $apply, &$stamped, &$left) {
                foreach ($targets as $row) {
                    $period = $resolver->resolve((int) $row->facility_id, $row->{$date});
                    if ($period === null) {
                        $left++;

                        continue;
                    }
                    $stamped++;
                    if ($apply) {
                        DB::table($table)->where('id', $row->id)->whereNull('reporting_period_id')->update(['reporting_period_id' => $period]);
                    }
                }
            };
            if ($apply) {
                DB::transaction($write);
            } else {
                $write();
            }
            $rows[] = [$table, $stamped, $left];
        }
        $this->table(['table', 'stamped', 'left_null'], $rows);

        return self::SUCCESS;
    }
}
