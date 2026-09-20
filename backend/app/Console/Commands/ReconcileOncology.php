<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileOncology extends Command
{
    protected $signature = 'oncology:reconcile';

    protected $description = 'Read-only legacy oncology counts and relationship/duplicate candidates; never converts clinical facts';

    public function handle(): int
    {
        $report = DB::transaction(function () {
            $result = ['read_only' => true, 'automatic_conversion' => false];
            foreach (['cancer_cases', 'cancer_case_diagnoses', 'cancer_treatments', 'dose_sessions', 'dose_session_items', 'visit_medications'] as $table) {
                $result[$table] = DB::table($table)->count();
            }
            foreach (['dose_sessions' => 'administered_on', 'visit_medications' => 'dispensed_on'] as $table => $date) {
                $q = DB::table($table.' as e')->leftJoin('visits as v', 'v.id', '=', 'e.visit_id');
                $result[$table.'_orphan_visits'] = (clone $q)->whereNull('v.id')->count();
                $result[$table.'_scope_mismatches'] = (clone $q)->whereColumn('e.facility_id', '<>', 'v.facility_id')->count();
                $result[$table.'_unlinked_context'] = (clone $q)->whereNull('v.dossier_id')->count();
                $result[$table.'_date_mismatches'] = (clone $q)->whereColumn('e.'.$date, '<>', 'v.visit_date')->count();
                $groups = DB::table($table)->whereNull('voided_at')->select('visit_id', $date)->groupBy('visit_id', $date)->havingRaw('COUNT(*) > 1');
                $result[$table.'_duplicate_candidates_not_proven'] = DB::query()->fromSub($groups, 'g')->count();
            }
            $result['orphan_dose_items'] = DB::table('dose_session_items as i')->leftJoin('dose_sessions as s', 's.id', '=', 'i.dose_session_id')->whereNull('s.id')->count();
            $result['orphan_legacy_treatments'] = DB::table('cancer_treatments as t')->leftJoin('cancer_cases as c', 'c.id', '=', 't.cancer_case_id')->whereNull('c.id')->count();
            $result['legacy_narratives_not_convertible'] = DB::table('cancer_treatments')->where(fn ($q) => $q->whereNotNull('description')->orWhereNotNull('note'))->count();
            $result['duplicate_administered_item_candidates'] = DB::query()->fromSub(DB::table('dose_session_items')->select('dose_session_id', 'medication_name_snapshot')->groupBy('dose_session_id', 'medication_name_snapshot')->havingRaw('COUNT(*) > 1'), 'g')->count();

            return $result;
        });
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
