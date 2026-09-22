<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertSafe($app);

$kernel = $app->make(HttpKernel::class);
$request = Request::create('/api/stock/receipts/'.$argv[2].'/confirm', 'POST', [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer '.$argv[1],
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
], json_encode(['facility_id' => (int) $argv[3], 'lock_version' => (int) $argv[4]]));
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
exit($response->getStatusCode() < 300 ? 0 : 1);
