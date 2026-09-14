<?php

use App\Models\User;
use App\Services\BloodBank\BloodBankAccess;
use App\Services\BloodBank\BloodBankWriter;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = realpath($argv[1]);
$root = realpath(storage_path('framework/testing')).DIRECTORY_SEPARATOR;
if (! $path || ! str_starts_with($path, $root)) {
    throw new RuntimeException('Only local test payloads.');
}
$f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($f['user_id']);
if (! str_starts_with($user->username, 'catalog-')) {
    throw new RuntimeException('Synthetic user only.');
}
$request = Request::create('/api/blood-bank/test', 'PUT', $f['payload']);
$request->setUserResolver(fn () => $user);
$facility = app(BloodBankAccess::class)->facility($user, $f['payload']['facility_id'], 'donations.update');
// Pause only after the real writer acquires its lock, preserving its lock order.
// Prelocking the donor before the idempotency row introduces an artificial inversion.
$paused = false;
DB::listen(function ($query) use (&$paused) {
    if (! $paused && str_contains($query->sql, '`blood_donors`') && str_contains($query->sql, 'for update')) {
        $paused = true;
        echo "DONOR_LOCKED\n";
        flush();
        usleep(800000);
    }
});
app(BloodBankWriter::class)->donation($request, $facility, $f['donor'], $f['payload'], $f['donation']);
