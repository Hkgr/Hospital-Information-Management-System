<?php

namespace App\Services\Audit;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SystemActivity
{
    public function record(Request $request, ?int $facilityId, ?int $actorId, string $entityType, int $entityId, string $event, ?array $old, ?array $new, ?string $reason = null): void
    {
        if (! $facilityId || ! $actorId) {
            return;
        }
        try {
            DB::table('audit_logs')->insert([
                'facility_id' => $facilityId, 'actor_id' => $actorId,
                'entity_type' => $entityType, 'entity_id' => $entityId, 'event' => $event,
                'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'reason' => $reason, 'request_id' => (string) Str::uuid(),
                'ip_address' => $request->ip(), 'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            // Logging must never fail the original action.
        }
    }

    public function auth(Request $request, int $userId, string $event, string $username): void
    {
        $facility = $this->facilityFor($request, $userId);
        $this->record($request, $facility, $userId, 'auth_session', $userId, $event, null, ['username' => $username]);
    }

    public function recordException(Throwable $e): void
    {
        if ($this->ignored($e)) {
            return;
        }
        $request = request();
        if (! $request instanceof Request) {
            return;
        }
        [$facility, $actor] = $this->context($request);
        $payload = $e instanceof QueryException
            ? ['message' => 'تعذّر حفظ البيانات بسبب خطأ تقني.', 'type' => 'QueryException']
            : ['message' => $this->redact($e->getMessage()) ?: 'تعذّر إتمام العملية.', 'type' => class_basename($e)];
        $payload += ['path' => '/'.ltrim($request->path(), '/'), 'method' => $request->method()];
        $this->record($request, $facility, $actor, 'system_error', 0, 'failed', null, $payload);
    }

    private function ignored(Throwable $e): bool
    {
        if ($e instanceof ValidationException || $e instanceof HttpResponseException || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException || $e instanceof MissingAbilityException) {
            return true;
        }
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() < 500;
        }

        return false;
    }

    /** @return array{0: ?int, 1: ?int} */
    private function context(Request $request): array
    {
        $actor = $request->user()?->id;
        $facility = $this->facilityFor($request, $actor);

        return [$facility, $actor ? (int) $actor : null];
    }

    private function facilityFor(Request $request, ?int $userId): ?int
    {
        $given = $request->input('facility_id') ?? $request->query('facility_id');
        if (is_numeric($given) && (int) $given > 0) {
            if (! $userId) {
                return (int) $given;
            }
            $owned = DB::table('facility_user_roles as a')->join('facilities as f', 'f.id', '=', 'a.facility_id')
                ->where('a.user_id', $userId)->where('f.id', (int) $given)->where('f.is_active', true)->exists();

            return $owned ? (int) $given : $this->firstFacility($userId);
        }

        return $userId ? $this->firstFacility($userId) : null;
    }

    private function firstFacility(int $userId): ?int
    {
        $id = DB::table('facility_user_roles as a')->join('facilities as f', 'f.id', '=', 'a.facility_id')
            ->where('a.user_id', $userId)->where('f.is_active', true)->orderBy('f.code')->orderBy('f.id')->value('f.id');

        return $id ? (int) $id : null;
    }

    private function redact(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/(?i)(password|token|secret|authorization)([^\s]*)/', '[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 300);
    }
};
