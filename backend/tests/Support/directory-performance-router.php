<?php

// Local benchmark instrumentation only; never registered in application routes.
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Local CLI test server only.');
}
$started = microtime(true);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
TestDatabaseSafety::assertAvailable($app);
config(['clinics.doctor_staff_types' => ['PERF_DOCTOR']]);
config(['logging.default' => 'single', 'logging.channels.single.path' => storage_path('framework/testing/performance-errors.log')]);
$count = 0;
$time = 0;
DB::listen(function ($event) use (&$count, &$time) {
    $count++;
    $time += $event->time;
});
$response = $kernel->handle($request = Request::capture());
$response->headers->set('X-Test-Sql-Count', (string) $count);
$response->headers->set('X-Test-Sql-Ms', (string) round($time, 2));
$response->headers->set('X-Test-Server-Ms', (string) round((microtime(true) - $started) * 1000, 2));
$response->send();
$kernel->terminate($request, $response);
