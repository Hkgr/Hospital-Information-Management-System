<?php

use App\Exceptions\BloodBankException;
use App\Models\User;
use App\Services\BloodBank\BloodBankAccess;
use App\Services\BloodBank\BloodEventWriter;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = realpath($argv[1] ?? '');
if (! $path || ! str_starts_with($path, realpath(storage_path('framework/testing')).DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Local test payload only.');
}
$input = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$user = User::findOrFail($input['user_id']);
if (! str_starts_with($user->username, 'catalog-')) {
    throw new RuntimeException('Synthetic user only.');
}
$request = Request::create('/api/blood-bank/events/'.$input['event'], 'PUT', $input['payload']);
$request->setUserResolver(fn () => $user);
$facility = app(BloodBankAccess::class)->facility($user, $input['payload']['facility_id']);
$paused = false;
DB::listen(function ($query) use (&$paused, $input) {
    if (! $paused && ($input['pause'] ?? false) && str_contains($query->sql, '`facilities`') && str_contains($query->sql, 'for update')) {
        $paused = true;
        echo "LOCKED\n";
        flush();
        usleep(1200000);
    }
});
try {
    app(BloodEventWriter::class)->save($request, $facility, $input['payload'], $input['event']);
    echo "SAVED\n";
} catch (BloodBankException $e) {
    if ($e->errorCode !== 'BLOOD_BANK_VERSION_CONFLICT') {
        throw $e;
    }
    echo "CONFLICT\n";
}
