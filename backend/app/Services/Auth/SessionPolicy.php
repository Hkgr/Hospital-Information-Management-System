<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SessionPolicy
{
    public const DEFAULT_SECONDS = 120;

    public static function seconds(mixed $minutes): int
    {
        return is_numeric($minutes) && (int) $minutes == $minutes && $minutes >= 1 && $minutes <= 60
            ? (int) $minutes * 60 : self::DEFAULT_SECONDS;
    }

    /** Called inside the token transaction. Shared policy locks serialize renewal with settings writes. */
    public function forUser(User $user, ?string $since = null, array $previousScope = []): array
    {
        $system = (bool) app(GlobalAccess::class)->systemRole($user);
        // This existing singleton also serializes creation of missing facility settings.
        $global = DB::table('system_session_policy')->where('id', 1)->sharedLock()->first();
        $ids = $system ? [] : collect(app(UserAccessContext::class)->forUser($user))->pluck('facility.id')->all();
        $settings = $ids ? DB::table('facility_settings')->whereIn('facility_id', $ids)->orderBy('facility_id')->sharedLock()->get()->keyBy('facility_id') : collect();
        $seconds = $system ? self::seconds($global?->idle_minutes) : ($ids ? min(array_map(fn ($id) => self::seconds($settings->get($id)?->idle_minutes), $ids)) : self::DEFAULT_SECONDS);
        $minimum = $seconds;
        $historyIds = array_values(array_unique([...$ids, ...($previousScope['facilities'] ?? [])]));
        $historySystem = $system || ($previousScope['system'] ?? false);
        if ($since !== null && ($historySystem || $historyIds)) {
            $changes = DB::table('session_policy_changes')->where('changed_at', '>=', $since);
            $changes->where(function ($q) use ($historyIds, $historySystem) {
                $q->whereIn('facility_id', $historyIds);
                if ($historySystem) {
                    $q->orWhereNull('facility_id');
                }
            });
            $historical = $changes->sharedLock()->pluck('idle_seconds')->min();
            if ($historical !== null) {
                $minimum = min($minimum, self::seconds($historical / 60));
            }
        }

        return ['idle_timeout' => $seconds, 'minimum_since_activity' => $minimum,
            'warning_seconds' => min(60, max(15, (int) ceil($seconds / 4))),
            'scope' => $system ? 'system' : 'facilities',
            'scope_snapshot' => ['system' => $system, 'facilities' => $ids],
            'observed_scope' => ['system' => $historySystem, 'facilities' => $historyIds]];
    }
}
