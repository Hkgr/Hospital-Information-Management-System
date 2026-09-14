<?php

use App\Services\Dossiers\DossierQueries;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
DB::beginTransaction();
try {
    $f = DossierFixture::make();
    $visit = (array) DB::table('visits')->where('id', $f['latest_visit'])->first();
    unset($visit['id']);
    foreach (range(0, 11) as $batch) {
        $people = [];
        for ($n = 0; $n < 500; $n++) {
            $people[] = ['patient_code' => 'VOLUME-'.$f['tag'].'-'.($batch * 500 + $n), 'first_name' => 'أحمد', 'family_name' => 'اختبار الحجم', 'search_name' => 'أحمد اختبار الحجم', 'identity_document_type' => 'unknown', 'created_by' => $f['user']->id];
        }
        DB::table('patients')->insert($people);
        $ids = DB::table('patients')->whereIn('patient_code', array_column($people, 'patient_code'))->pluck('id');
        DB::table('patient_dossiers')->insert($ids->map(fn ($id) => ['facility_id' => $f['facility'], 'patient_id' => $id, 'code' => 'VOL-'.$id, 'opening_date' => '2020-01-01', 'entered_by' => $f['user']->id, 'status' => 'active'])->all());
        $dossiers = DB::table('patient_dossiers')->where('facility_id', $f['facility'])->whereIn('patient_id', $ids)->get(['id', 'patient_id']);
        DB::table('visits')->insert($dossiers->map(fn ($d) => array_replace($visit, ['dossier_id' => $d->id, 'patient_id' => $d->patient_id, 'visit_no' => 'VOL-'.$f['tag'].'-'.$d->id, 'client_request_id' => (string) Str::uuid()]))->all());
    }
    $out = ['version' => DB::selectOne('SELECT VERSION() AS version')->version, 'synthetic_dossiers' => 6010, 'queries' => []];
    foreach (['default' => [], 'substring' => ['search' => 'أحمد اختبار']] as $label => $filters) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $start = microtime(true);
        $page = app(DossierQueries::class)->listing(['id' => $f['facility'], 'today' => $f['today']], $filters);
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $plans = [];
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'patient_dossiers')) {
                $plans[] = DB::select('EXPLAIN '.$query['query'], $query['bindings']);
            }
        }
        $out['queries'][$label] = ['total' => $page['meta']['total'], 'returned' => count($page['data']), 'query_count' => count($queries), 'elapsed_ms_single_sample' => $elapsed, 'plans' => $plans];
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} finally {
    DB::rollBack();
}
