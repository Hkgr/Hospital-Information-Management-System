<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(ConsoleKernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$file = realpath($argv[1] ?? '');
if (! $file || ! str_starts_with($file, realpath(storage_path('framework/testing')).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Fixture must be inside isolated testing storage.');
}
$fixture = json_decode(file_get_contents($file), true);
$request = Request::create('/api/session/activity', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$fixture['token']]);
echo "READY\n";
flush();
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)])."\n";
$kernel->terminate($request, $response);
