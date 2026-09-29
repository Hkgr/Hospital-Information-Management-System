<?php

// Provision a separate empty local *_testing database BEFORE invoking this runner.
// It migrates to develop, populates synthetic history, then exercises a real upgrade.
// Never creates/drops databases, uses migrate:fresh, or edits the migration ledger.
use App\Services\Directory\ClinicalDirectorySetup;
use App\Support\TestDatabaseSafety;
use Database\Seeders\ClinicalStaffTypesSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DossierCompletionFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$completed = false;
register_shutdown_function(static function () use (&$completed): void {
    if (! $completed) {
        exit(1);
    }
});
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$mode = $argv[1] ?? '';
$legacy = $argv[2] ?? '';
if (! in_array($mode, ['develop', 'applied'], true) || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Use develop|applied with a local isolated database only.');
}
if ($mode === 'applied' && (! is_file($legacy) || hash('sha256', str_replace("\r\n", "\n", file_get_contents($legacy))) !== 'c55a832e23cbc339ef0a2269453a0b386089c31488c8cab9abc403c3ac4502fe')) {
    throw new RuntimeException('Applied path requires the exact migration from d2a2650, exported as its original filename.');
}
$database = DB::connection()->getDatabaseName();
$tables = fn () => DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->where('TABLE_TYPE', 'BASE TABLE')->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();
if ($tables()) {
    throw new RuntimeException('Runner requires an empty separately provisioned database; it will not reset an existing one.');
}
echo "Guard passed: testing/mysql/$database on ".config('database.connections.mysql.host').':'.config('database.connections.mysql.port')."; mode=$mode\n";
$migrate = function (array $paths = []) {
    $args = ['--force' => true];
    if ($paths) {
        $args += ['--path' => $paths, '--realpath' => true];
    }
    if (Artisan::call('migrate', $args) !== 0) {
        throw new RuntimeException('Migration failed: '.Artisan::output());
    }
};
$boundary = '2026_09_29_000001_add_clinical_entry_classifications.php';
$files = glob(database_path('migrations/*.php'));
$base = array_values(array_filter($files, fn ($path) => basename($path) < $boundary));
$migrate($base);
if (Schema::hasColumn('staff', 'practice_group')) {
    throw new RuntimeException('Baseline unexpectedly contains the pending classification schema.');
}
$f = DB::transaction(fn () => DossierCompletionFixture::make());
$type = DB::table('staff')->where('id', $f['workflow_doctors'][0])->value('staff_type_id');
$duplicates = [];
foreach (['إيمان   المحمد', 'ايمان المحمد'] as $index => $name) {
    $duplicates[] = DB::table('staff')->insertGetId(['staff_code' => 'UPGRADE-AMB-'.$index, 'full_name' => $name, 'search_name' => $name, 'staff_type_id' => $type, 'license_no' => '000'.$index]);
}
DB::table('blood_components')->insert(['code' => 'OLD-WHOLE', 'name_ar' => 'كامل', 'registration_kind' => 'whole']);

$digest = function (string $table, array $columns): string {
    $rows = DB::table($table)->select($columns)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
    sort($rows, SORT_STRING);

    return hash('sha256', implode("\n", $rows));
};
$snapshot = function (array $selected) use ($digest): array {
    $out = [];
    foreach ($selected as $table) {
        $columns = Schema::getColumnListing($table);
        $out[$table] = [$columns, $digest($table, $columns)];
    }

    return $out;
};
$same = function (array $before, string $step) use ($digest): void {
    foreach ($before as $table => [$columns, $hash]) {
        if ($hash !== $digest($table, $columns)) {
            throw new RuntimeException("$step changed retained rows in $table");
        }
    }
    echo "$step: ".count($before)." table digests unchanged.\n";
};
$directory = ['staff_types', 'staff', 'clinics', 'clinic_staff', 'procedures', 'blood_components', 'number_sequences', 'audit_logs'];
$medical = array_values(array_diff($tables(), [...$directory, 'migrations']));
$clinical = $snapshot($medical);
if ($mode === 'applied') {
    // The normal migrator records the authentic old migration; no ledger shortcuts.
    $migrate([realpath($legacy)]);
    if (! DB::table('staff')->whereNotNull('practice_group')->exists()) {
        throw new RuntimeException('Legacy migration did not execute its original directory writes.');
    }
    $same($clinical, 'Authentic already-applied baseline medical history');
}
$before = $snapshot(array_values(array_diff($tables(), ['migrations'])));
$migrate();
$same($before, 'Schema upgrade without directory approval');
$same($clinical, 'Medical history after upgrade');
$after = $snapshot(array_values(array_diff($tables(), ['migrations'])));
$migrate();
$same($after, 'Repeat migration');

$setup = app(ClinicalDirectorySetup::class);
$assertBlocked = function (string $label, string $expected) use ($setup, $f, $snapshot, $same, $directory): void {
    $before = $snapshot($directory);
    $plan = $setup->preview($f['facility'], '2001-01-01');
    if (! str_contains(implode(' ', $plan['errors']), $expected)) {
        throw new RuntimeException("Missing expected refusal: $label");
    }
    try {
        $setup->apply($f['facility'], '2001-01-01', [], $plan['fingerprint'], 'test/upgrade');
        throw new LogicException('Invalid setup unexpectedly applied');
    } catch (RuntimeException $e) {
        if (! str_contains($e->getMessage(), $expected)) {
            throw $e;
        }
    }
    $same($before, $label);
};
$assertBlocked('Missing explicit staff types', 'RESIDENT');
app(ClinicalStaffTypesSeeder::class)->run();
$assertBlocked('Ambiguous normalized names with valid types', 'ملتبس');
// Explicit synthetic administrative resolution; never delete or merge the existing records.
DB::table('staff')->where('id', $duplicates[1])->update(['full_name' => 'اسم اصطناعي مستقل', 'search_name' => 'اسم اصطناعي مستقل']);
$before = $snapshot($directory);
$plan = $setup->preview($f['facility'], '2001-01-01');
if ($plan['errors']) {
    throw new RuntimeException('Resolved fixture still ambiguous: '.implode('; ', $plan['errors']));
}
if (Artisan::call('directory:clinical-setup', ['--facility' => $f['facility'], '--starts-on' => '2001-01-01', '--apply' => true]) === 0) {
    throw new RuntimeException('Setup applied without approval fingerprint/operator');
}
$same($before, 'Absent approval even with a valid preview');
$setup->apply($f['facility'], '2001-01-01', [], $plan['fingerprint'], 'test/approved-upgrade');
$plan = $setup->preview($f['facility'], '2001-01-01');
foreach ($plan['doctors'] as $entry) {
    if ($entry['before']->staff_type_id !== $entry['staff_type_id'] || $entry['before']->practice_group !== $entry['practice_group']) {
        throw new RuntimeException('Approved setup did not correct the explicit doctor type');
    }
}
$before = $snapshot($directory);
$setup->apply($f['facility'], '2001-01-01', [], $plan['fingerprint'], 'test/approved-repeat');
$same($before, 'Repeated approved setup');
$same($clinical, 'Final medical history');
echo "PASS $mode: populated upgrade, blocked unapproved/ambiguous/missing-type writes, approved correction and repeatability. Database retained.\n";
$completed = true;
