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
$input = json_decode(stream_get_contents(STDIN), true);
if (! preg_match('/^[a-f0-9-]{36}$/', $input['gate'] ?? '')) {
    throw new RuntimeException('Invalid barrier');
}
$gate = storage_path('framework/testing/idle-'.$input['gate']);
echo "READY\n";
flush();
$end = microtime(true) + 15;
while (! file_exists($gate) && microtime(true) < $end) {
    usleep(10000);
}
if (! file_exists($gate)) {
    throw new RuntimeException('Barrier timeout');
}
$request = Request::create('/api/session/activity', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'HTTP_ACCEPT' => 'application/json']);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode()])."\n";
$kernel->terminate($request, $response);
