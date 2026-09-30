<?php

namespace App\Services\Reception;

use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

class ReviewAccess
{
    public function facility(Request $r, string $permission): array
    {
        $permission = ['reception.view' => 'patients.basic.view', 'reception.correct' => 'patients.own.correct', 'reception.corrections.request' => 'patients.corrections.request'][$permission] ?? $permission;
        $r->validate(['facility_id' => 'required|integer|min:1']);
        foreach (app(UserAccessContext::class)->forUser($r->user()) as $entry) {
            if ($entry['facility']['id'] === $r->integer('facility_id') && in_array($permission, $entry['permissions'], true)) {
                return $entry['facility'] + ['permissions' => $entry['permissions'], 'today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }
        abort(403, 'لا تملك صلاحية هذه العملية في المنشأة المحددة.');
    }

    public function global(Request $r, string $permission): void
    {
        abort_unless(app(GlobalAccess::class)->allows($r->user(), $permission), 403, 'يلزم تفويض عالمي صريح لتغيير الهوية المشتركة.');
    }

    public static function conflict(string $message, string $code = 'REVIEW_VERSION_CONFLICT'): never
    {
        throw new HttpResponseException(response()->json(['error' => ['code' => $code, 'message' => $message]], 409));
    }
}
