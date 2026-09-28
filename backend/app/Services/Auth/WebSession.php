<?php

namespace App\Services\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebSession
{
    public const TIMEOUT = 120;

    /** No general API request, polling or GET extends a web token. */
    public function check(Request $r, bool $activity = false): ?array
    {
        $id = $r->user()?->currentAccessToken()?->id;
        abort_unless($id, 401);
        $result = DB::transaction(function () use ($r, $id, $activity) {
            $token = DB::table('personal_access_tokens')->where('id', $id)->lockForUpdate()->first();
            if (! $token) {
                return false;
            }
            // Operator-issued noninteractive PATs are intentionally separate;
            // login clients cannot choose this exemption using device_name.
            if ($token->web_idle_deadline === null) {
                return null;
            }
            $now = now(); // Read after acquiring the lock, not before a lock wait.
            if ($token->web_expired_at || $now->greaterThanOrEqualTo(CarbonImmutable::parse($token->web_idle_deadline))) {
                if (! $token->web_expired_at) {
                    DB::table('personal_access_tokens')->where('id', $id)->update(['web_expired_at' => $now->format('Y-m-d H:i:s.u')]);
                    $facility = collect(app(UserAccessContext::class)->forUser($r->user()))->first()['facility']['id'] ?? null;
                    if ($facility) {
                        DB::table('audit_logs')->insert([
                            'facility_id' => $facility, 'actor_id' => $r->user()->id, 'entity_type' => 'auth_session', 'entity_id' => $r->user()->id,
                            'event' => 'expired', 'new_values' => json_encode(['reason' => 'idle_timeout']), 'request_id' => (string) Str::uuid(), 'occurred_at' => $now,
                        ]);
                    } else {
                        // The facility audit requires a real facility. Do not
                        // invent membership for an account with no access.
                        $actor = $r->user()->id;
                        $occurredAt = $now->toIso8601String();
                        DB::afterCommit(fn () => Log::info('web_session_expired', ['actor_id' => $actor, 'occurred_at' => $occurredAt, 'reason' => 'idle_timeout']));
                    }
                }

                return false;
            }
            $deadline = $activity ? $now->copy()->addSeconds(self::TIMEOUT) : CarbonImmutable::parse($token->web_idle_deadline);
            if ($activity) {
                DB::table('personal_access_tokens')->where('id', $id)->update(['web_idle_deadline' => $deadline->format('Y-m-d H:i:s.u')]);
            }

            return ['idle_timeout' => self::TIMEOUT, 'remaining_seconds' => max(0, (int) floor($now->diffInSeconds($deadline, false))),
                'server_time' => $now->toIso8601String(), 'expires_at' => $deadline->toIso8601String()];
        }, 3);
        // Commit the expiration marker/audit before returning 401.
        if ($result === false) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'SESSION_IDLE_EXPIRED', 'message' => 'انتهت الجلسة بسبب الخمول']], 401)->header('Cache-Control', 'private, no-store'));
        }

        return $result;
    }
}
