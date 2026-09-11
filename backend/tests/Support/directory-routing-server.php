<?php

// Transparent test entry point: real Laravel kernel/routes/auth/database, no mocks.
// The marker distinguishes a forwarded Laravel response from Next's own 404.
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Local CLI test server only.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
TestDatabaseSafety::assertAvailable($app);
config(['clinics.doctor_staff_types' => ['ROUTING_DOCTOR']]);
$response = $kernel->handle($request = Request::capture());
$response->headers->set('X-Test-Laravel', 'directory-routing');
$response->send();
$kernel->terminate($request, $response);
