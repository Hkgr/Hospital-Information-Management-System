<?php

use App\Models\User;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierPathology;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($payload['user']);
$f = app(DossierAccess::class)->facility($user, $payload['facility'], 'pathology.'.($payload['id'] ? 'update' : 'create'));
$r = Request::create('/testing-pathology', 'POST');
$r->setUserResolver(fn () => $user);
echo "READY\n";
flush();
// A real second connection overlaps the parent's held context lock.
try {
    $id = app(DossierPathology::class)->save($r, $f, $payload['dossier'], $payload['visit'], $payload['data'], $payload['id']);
    echo json_encode(['status' => 200, 'id' => $id])."\n";
} catch (HttpResponseException $e) {
    echo json_encode(['status' => $e->getResponse()->getStatusCode()])."\n";
}
