<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$result = json_decode(file_get_contents(storage_path('framework/testing/doctor-live-result.json')), true, 512, JSON_THROW_ON_ERROR);
$doctor = DB::table('staff')->find($result['doctor_id']);
if (! $doctor || (int) $doctor->lock_version !== $result['lock_version'] || DB::table('clinic_staff')->where('staff_id', $doctor->id)->whereIn('clinic_id', $result['clinics'])->whereNull('ends_on')->count() !== 2 || ! DB::table('audit_logs')->where('entity_type', 'doctor')->where('entity_id', $doctor->id)->where('event', 'exported')->exists()) {
    throw new RuntimeException('Live persisted state does not match HTTP assertions.');
}
echo "PASS persisted MySQL rows: staff version, two current clinic links, export audit.\n";
