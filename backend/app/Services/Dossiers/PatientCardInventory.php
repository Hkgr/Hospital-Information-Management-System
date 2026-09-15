<?php

namespace App\Services\Dossiers;

use Illuminate\Support\Facades\DB;

/** Read-only legacy inventory. Never guesses which encounter belongs to a context. */
class PatientCardInventory
{
    public function details(): array
    {
        return [
            'patients_without_contexts' => DB::table('patients as p')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('patient_dossiers as d')->whereColumn('d.patient_id', 'p.id'))->pluck('p.id')->all(),
            'contexts_without_visits' => DB::table('patient_dossiers as d')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('visits as v')->whereColumn('v.dossier_id', 'd.id'))->get(['d.id', 'd.patient_id', 'd.facility_id'])->all(),
            'multiple_contexts' => DB::table('patient_dossiers')->select('patient_id')->selectRaw('COUNT(*) AS contexts')->groupBy('patient_id')->havingRaw('COUNT(*) > 1')->get()->all(),
            'code_mismatches' => DB::table('patient_dossiers as d')->join('patients as p', 'p.id', '=', 'd.patient_id')->whereColumn('d.code', '<>', 'p.patient_code')->get(['d.id', 'd.patient_id', 'd.facility_id'])->all(),
            'ambiguous_alias_contexts' => DB::table('patient_dossiers as d')->whereExists(fn ($q) => $q->selectRaw('1')->from('patient_dossiers as other')->whereColumn('other.code', 'd.code')->whereColumn('other.patient_id', '<>', 'd.patient_id'))->get(['d.id', 'd.patient_id', 'd.facility_id'])->all(),
            'alias_canonical_collisions' => DB::table('patient_dossiers as d')->join('patients as p', 'p.patient_code', '=', 'd.code')->whereColumn('p.id', '<>', 'd.patient_id')->get(['d.id', 'd.patient_id', 'p.id as other_patient_id'])->all(),
            'invalid_canonical_patient_ids' => DB::table('patients')->whereRaw("TRIM(patient_code) = '' OR BINARY patient_code <> BINARY TRIM(patient_code)")->pluck('id')->all(),
            'unlinked_visit_ids' => DB::table('visits')->whereNull('dossier_id')->pluck('id')->all(),
        ];
    }

    public function report(): array
    {
        return DB::transaction(function () {
            $cards = DB::table('patient_dossiers as d');
            $groups = (clone $cards)->select('patient_id')->groupBy('patient_id')->havingRaw('COUNT(*) > 1');
            $multiFacility = (clone $cards)->select('patient_id')->groupBy('patient_id')->havingRaw('COUNT(DISTINCT facility_id) > 1');
            $aliases = (clone $cards)->whereNotNull('code')->select('code')->groupBy('code')->havingRaw('COUNT(DISTINCT patient_id) > 1');
            $canonical = DB::table('patients')->select('patient_code')->groupBy('patient_code')->havingRaw('COUNT(*) > 1');

            return [
                'read_only' => true,
                'patients' => DB::table('patients')->count(),
                'facility_contexts' => (clone $cards)->count(),
                'patients_without_contexts' => DB::table('patients as p')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('patient_dossiers as d')->whereColumn('d.patient_id', 'p.id'))->count(),
                'contexts_without_visits' => (clone $cards)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('visits as v')->whereColumn('v.dossier_id', 'd.id')->whereColumn('v.patient_id', 'd.patient_id')->whereColumn('v.facility_id', 'd.facility_id'))->count(),
                'patients_with_multiple_contexts' => DB::query()->fromSub($groups, 'g')->count(),
                'patients_with_multiple_facility_contexts' => DB::query()->fromSub($multiFacility, 'g')->count(),
                'context_code_mismatches' => (clone $cards)->join('patients as p', 'p.id', '=', 'd.patient_id')->whereColumn('d.code', '<>', 'p.patient_code')->count(),
                'ambiguous_legacy_code_groups' => DB::query()->fromSub($aliases, 'g')->count(),
                'legacy_codes_colliding_with_other_patient_codes' => (clone $cards)->join('patients as p', 'p.patient_code', '=', 'd.code')->whereColumn('p.id', '<>', 'd.patient_id')->count(),
                'duplicate_canonical_code_groups' => DB::query()->fromSub($canonical, 'g')->count(),
                'invalid_canonical_codes' => DB::table('patients')->whereRaw("TRIM(patient_code) = '' OR BINARY patient_code <> BINARY TRIM(patient_code)")->count(),
                'visits_without_context' => DB::table('visits')->whereNull('dossier_id')->count(),
            ];
        });
    }
}
