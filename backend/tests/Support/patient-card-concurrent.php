<?php

// Two independent PHP workers execute the real HTTP kernel against the live
// synthetic fixture. Credentials stay in the existing ignored fixture file.
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$f = json_decode(file_get_contents(storage_path('framework/testing/dossier-workflow-live.json')), true, 512, JSON_THROW_ON_ERROR);
$job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
echo "READY\n";
flush();
$deadline = microtime(true) + 15;
while (! is_file(storage_path('framework/testing/card-concurrent-'.$job['gate']))) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency gate timed out before request; test did not execute.');
    }
    usleep(10000);
}
$request = Request::create('/api/dossiers', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$f['token'], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($job['body'] + ['facility_id' => $f['facility']]));
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'id' => json_decode($response->getContent(), true)['data']['card_id'] ?? null], JSON_THROW_ON_ERROR)."\n";
$kernel->terminate($request, $response);
