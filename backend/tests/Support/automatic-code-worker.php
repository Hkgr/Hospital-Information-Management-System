<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
echo "READY\n";
flush();
file_put_contents($argv[1].'.ready.'.$argv[2], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($argv[1].'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Parent never released the readiness barrier.');
    }
    usleep(10000);
    clearstatcache(true, $argv[1].'.go');
}
$kernel = $app->make(HttpKernel::class);
$request = Request::create('/api/clinics', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture['token'], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($fixture['requests'][(int) $argv[2]]));
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$data = json_decode($response->getContent(), true);
echo json_encode(['status' => $response->getStatusCode(), 'id' => $data['data']['id'] ?? null, 'code' => $data['data']['code'] ?? null]);
exit($response->getStatusCode() === 201 ? 0 : 1);
