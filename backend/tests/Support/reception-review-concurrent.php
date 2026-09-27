<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$fixture = json_decode(file_get_contents(storage_path('framework/testing/reception-review-live.json')), true);
$job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (! preg_match('/^[a-f0-9-]{36}$/', $job['gate'])) {
    throw new RuntimeException('Invalid test barrier');
}
echo "READY\n";
flush();
$deadline = microtime(true) + 20;
while (! is_file(storage_path('framework/testing/review-'.$job['gate']))) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker readiness timeout');
    }
    usleep(10000);
}
$request = Request::create('/api/reception/cards/'.(int) $job['card'].'/correct', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture['clerk']['token'], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($job['body'] + ['facility_id' => $fixture['facility']]));
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode()])."\n";
$kernel->terminate($request, $response);
