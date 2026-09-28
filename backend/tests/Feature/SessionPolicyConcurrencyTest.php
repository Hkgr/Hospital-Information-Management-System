<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SessionPolicyConcurrencyTest extends TestCase
{
    // Intentionally committed synthetic fixture: no migration/fresh or global seeding.
    public function test_activity_waiting_for_token_lock_cannot_renew_after_deadline(): void
    {
        TestDatabaseSafety::assertAvailable($this->app);
        $user = User::factory()->create();
        $token = $user->createToken('concurrent-web', ['api']);
        $token->accessToken->forceFill(['web_idle_deadline' => now()->addSeconds(120)->format('Y-m-d H:i:s.u'), 'web_last_activity_at' => now()->format('Y-m-d H:i:s.u')])->save();
        $file = storage_path('framework/testing/session-'.Str::uuid().'.json');
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, json_encode(['token' => $token->plainTextToken]));
        $worker = new Process([PHP_BINARY, 'tests/Support/session-activity-worker.php', $file], base_path(), ['APP_ENV' => 'testing']);
        $worker->setTimeout(15);
        try {
            DB::beginTransaction();
            DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->lockForUpdate()->first();
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $worker->getErrorOutput());
            // Set the deadline only after subprocess bootstrap readiness, so
            // slow startup cannot substitute for expiry during the lock wait.
            DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['web_idle_deadline' => now()->addSeconds(2)->format('Y-m-d H:i:s.u'), 'web_last_activity_at' => now()->subSeconds(118)->format('Y-m-d H:i:s.u')]);
            usleep(2300000);
            $this->assertTrue($worker->isRunning(), 'The activity request must still be waiting for the token lock.');
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $lines = array_values(array_filter(explode("\n", trim($worker->getOutput()))));
            $response = json_decode(end($lines), true);
            $this->assertSame(401, $response['status']);
            $this->assertSame('SESSION_IDLE_EXPIRED', $response['body']['error']['code']);
            $this->assertNotNull(DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->value('web_expired_at'));
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            $worker->stop();
            unlink($file);
            $user->tokens()->delete();
            $user->forceFill(['is_active' => false])->save();
        }
    }
}
