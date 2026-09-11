<?php

use App\Services\Clinics\ClinicQueries;
use App\Services\Doctors\DoctorQueries;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$stage = $argv[1] ?? '';
if (! in_array($stage, ['before', 'after'], true)) {
    throw new RuntimeException('Choose before or after.');
}
config(['clinics.doctor_staff_types' => ['PERF_DOCTOR']]);
$fixture = json_decode(file_get_contents(storage_path('framework/testing/directory-performance.json')), true, flags: JSON_THROW_ON_ERROR);
$facility = ['id' => $fixture['facilities'][0], 'today' => now('Asia/Damascus')->toDateString(), 'timezone' => 'Asia/Damascus'];
$plans = [];
foreach (['doctors' => DoctorQueries::class, 'clinics' => ClinicQueries::class] as $name => $service) {
    foreach (['code', 'patient_count'] as $sort) {
        $query = app($service)->query($facility, ['sort' => $sort])->limit(20);
        $plans[$name.'_'.$sort] = ['sql' => $query->toSql(), 'plan' => DB::select('EXPLAIN ANALYZE '.$query->toSql(), $query->getBindings())];
    }
}
file_put_contents(storage_path('framework/testing/directory-explain-'.$stage.'.json'), json_encode($plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo "EXPLAIN ANALYZE saved locally for four list/sort queries.\n";
