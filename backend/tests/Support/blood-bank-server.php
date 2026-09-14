<?php

// Real application, only test environment and explicit synthetic doctor type configuration.
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Loopback CLI testing only.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$fixture = storage_path('framework/testing/blood-bank-live.json');
if (is_file($fixture)) {
    $f = json_decode(file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
    config(['clinics.doctor_staff_types' => ['CAT-'.$f['tag']]]);
}
$response = $kernel->handle($request = Request::capture());
$response->headers->set('X-Test-Laravel', 'blood-bank');
$response->send();
$kernel->terminate($request, $response);
