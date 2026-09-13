<?php

use App\Models\User;
use App\Services\Catalog\CatalogQueries;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/catalog-live.json');
if (($argv[1] ?? '') === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Previous live fixture exists; revoke its test tokens with cleanup first.');
    }
    $fixture = DB::transaction(function () {
        $f = CatalogFixture::make();
        $f['token'] = $f['user']->createToken('catalog-live', ['api'])->plainTextToken;
        $f['viewer_token'] = $f['viewer']->createToken('catalog-live', ['api'])->plainTextToken;
        $f['user_id'] = $f['user']->id;
        $f['viewer_id'] = $f['viewer']->id;
        unset($f['user'], $f['viewer']);

        return $f;
    });
    file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
    $query = app(CatalogQueries::class)->query(['id' => $fixture['facility'], 'today' => $fixture['today']], []);
    $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
    file_put_contents(storage_path('framework/testing/catalog-explain.json'), json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo "Prepared synthetic live fixture and inspected EXPLAIN; tokens remain local.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    if (is_file($path)) {
        $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach (['user_id', 'viewer_id'] as $key) {
            $user = User::findOrFail($f[$key]);
            if (! str_starts_with($user->username, 'catalog-') || ! str_ends_with($user->username, $f['tag'])) {
                throw new RuntimeException('Not this synthetic account.');
            }
            $user->tokens()->where('name', 'catalog-live')->delete();
        }
        unlink($path);
    }
    echo "Revoked this run's tokens. Synthetic records and history retained in the isolated test database.\n";
} else {
    throw new RuntimeException('Use prepare or cleanup.');
}
