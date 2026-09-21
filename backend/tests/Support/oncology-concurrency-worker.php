<?php

use App\Models\User;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\OncologyWriter;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
config(['clinics.doctor_staff_types' => ['DWF-DOCTOR']]);
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user']);
$f = app(DossierAccess::class)->facility($user, $input['facility'], 'treatment.'.($input['id'] ? 'correct' : 'administer'));
$r = Request::create('/testing-oncology', 'POST');
$r->setUserResolver(fn () => $user);
echo "READY\n";
flush();
try {
    $id = app(OncologyWriter::class)->administer($r, $f, $input['dossier'], $input['visit'], $input['data'], $input['id']);
    echo json_encode(['status' => 200, 'id' => $id])."\n";
} catch (HttpResponseException $e) {
    echo json_encode(['status' => $e->getResponse()->getStatusCode()])."\n";
}
