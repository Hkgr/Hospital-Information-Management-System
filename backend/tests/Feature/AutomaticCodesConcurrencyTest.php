<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AutomaticCodesConcurrencyTest extends TestCase
{
    // Committed synthetic identities, without refreshing any populated database.
    public function test_simultaneous_same_and_distinct_creates_issue_exactly_three_codes(): void
    {
        TestDatabaseSafety::assertAvailable($this->app);
        $tag = (string) Str::uuid();
        $user = User::factory()->create();
        $facility = DB::table('facilities')->insertGetId(['code' => 'AUTO-'.substr($tag, 0, 20), 'name_ar' => 'اختبار تزامن الأكواد', 'timezone' => 'Asia/Damascus']);
        $role = DB::table('roles')->insertGetId(['code' => 'auto-'.substr($tag, 0, 20), 'name_ar' => 'اختبار التزامن']);
        foreach (['clinics.view', 'clinics.create'] as $code) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $code]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'facility_id' => $facility, 'role_id' => $role]);
        $data = ['facility_id' => $facility, 'request_id' => (string) Str::uuid(), 'name_ar' => 'عيادة تزامن', 'is_active' => true];
        $file = storage_path('framework/testing/automatic-'.$tag.'.json');
        file_put_contents($file, json_encode(['token' => $user->createToken('automatic-test', ['api'])->plainTextToken, 'requests' => [$data, $data, array_replace($data, ['request_id' => (string) Str::uuid()]), array_replace($data, ['request_id' => (string) Str::uuid()])]]));
        $workers = [];
        try {
            for ($n = 0; $n < 4; $n++) {
                $worker = new Process([PHP_BINARY, 'tests/Support/automatic-code-worker.php', $file, (string) $n], base_path(), ['APP_ENV' => 'testing']);
                $worker->setTimeout(40);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            foreach ($workers as $n => $worker) {
                while (! is_file($file.'.ready.'.$n) && microtime(true) < $deadline && $worker->isRunning()) {
                    usleep(10000);
                    clearstatcache(true, $file.'.ready.'.$n);
                }
                $this->assertFileExists($file.'.ready.'.$n, $worker->getErrorOutput().$worker->getOutput());
            }
            touch($file.'.go');
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = json_decode(substr($worker->getOutput(), strpos($worker->getOutput(), '{')), true);
            }
            $this->assertSame($results[0], $results[1]);
            $this->assertCount(3, array_unique(array_column($results, 'code')));
            $this->assertSame(3, DB::table('clinics')->where('facility_id', $facility)->count());
            $this->assertSame(3, DB::table('directory_creation_requests')->where('facility_id', $facility)->count());
            $this->assertSame(3, DB::table('audit_logs')->where('facility_id', $facility)->where('entity_type', 'clinic')->count());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            foreach ([$file, $file.'.go', ...array_map(fn ($n) => $file.'.ready.'.$n, range(0, 3))] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $user->tokens()->delete();
            $user->forceFill(['is_active' => false])->save();
        }
    }
}
