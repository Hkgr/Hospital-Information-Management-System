<?php

use App\Models\User;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierQueries;
use App\Services\Dossiers\OncologyQueries;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$p = DB::table('oncology_plans')->orderByDesc('id')->first();
if (! $p) {
    throw new RuntimeException('Run the synthetic oncology integration fixture first.');
}
$f = app(DossierAccess::class)->facility(User::findOrFail($p->entered_by), $p->facility_id, 'treatment.view');
$v = DB::table('visits')->where('dossier_id', $p->dossier_id)->where('facility_id', $p->facility_id)->whereNull('voided_at')->orderBy('id')->first();
$queries = app(DossierQueries::class);
$oncology = app(OncologyQueries::class);
$measure = function (string $label, callable $fn): int {
    DB::enableQueryLog();
    DB::flushQueryLog();
    $start = microtime(true);
    $result = $fn();
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();
    echo "$label: $n queries / $elapsed ms".(isset($result['data']) ? ' / '.count($result['data']).' rows' : '')."\n";

    return $n;
};
$small = $measure('Patient cards page 10', fn () => $queries->listing($f, ['per_page' => 10]));
$large = $measure('Patient cards page 50', fn () => $queries->listing($f, ['per_page' => 50]));
if ($large > $small + 2) {
    throw new RuntimeException('List queries grew with page size.');
}
$measure('Card detail', fn () => $queries->detail($f, $p->dossier_id));
$measure('Plan list', fn () => $oncology->listing($f, $p->dossier_id, []));
$measure('Plan revisions and items', fn () => $oncology->plan($f, $p->dossier_id, $p->id));
$measure('Scheduled sessions', fn () => $oncology->sessions($f, $p->dossier_id, []));
if ($v) {
    $measure('Selected visit doses and dispensing', fn () => $oncology->doses($f, $p->dossier_id, $v->id));
}
