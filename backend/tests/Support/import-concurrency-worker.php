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
config(['clinics.doctor_staff_types' => ['DWF-DOCTOR']]);
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
echo "READY\n";
flush();
$request = Request::create('/api/dossiers/imports/'.$input['batch'].'/commit', 'POST', server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token']], content: json_encode(['facility_id' => $input['facility'], 'lock_version' => $input['version'], 'confirm' => true]));
$response = $kernel->handle($request);
echo 'STATUS:'.$response->getStatusCode()."\n";
$kernel->terminate($request, $response);
