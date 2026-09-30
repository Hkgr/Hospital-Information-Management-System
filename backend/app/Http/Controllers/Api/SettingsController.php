<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;
use App\Services\Settings\FacilitySettings;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

#[Group('Facility settings')]
class SettingsController extends Controller
{
    /** Permission-scoped portal guide capabilities; never includes patient data. */
    public function guide(Request $request)
    {
        $request->validate(['facility_id' => 'required|integer|min:1']);
        $entry = collect(app(UserAccessContext::class)->forUser($request->user()))->firstWhere('facility.id', $request->integer('facility_id'));
        abort_unless($entry && $entry['permissions'], 403);

        return response()->json(['data' => ['facility' => $entry['facility'], 'permissions' => $entry['permissions'],
            'global_permissions' => app(GlobalAccess::class)->codes($request->user()),
            'system_admin' => (bool) app(GlobalAccess::class)->systemRole($request->user())]])->header('Cache-Control', 'private, no-store');
    }

    /** Requires settings.view in the explicit active facility. */
    public function show(Request $request, FacilitySettings $settings)
    {
        $request->validate(['facility_id' => 'required|integer|min:1']);

        return response()->json(['data' => $settings->show($request->user(), $request->integer('facility_id'))])->header('Cache-Control', 'private, no-store');
    }

    /** Requires settings.view/update; system scope additionally requires the protected global system role. No renewal. */
    public function update(Request $request, FacilitySettings $settings)
    {
        $system = $request->routeIs('settings.system');
        $rules = ['facility_id' => 'required|integer|min:1', 'lock_version' => 'required|integer|min:1',
            'idle_minutes' => 'required|integer|min:1|max:60', 'reason' => 'nullable|string|max:500'];
        if (! $system) {
            $rules['name_ar'] = 'required|string|max:200';
        }
        foreach (array_diff(array_keys($request->all()), array_keys($rules)) as $key) {
            throw ValidationException::withMessages([$key => 'هذا الحقل غير قابل للتعديل.']);
        }

        return response()->json(['data' => $settings->save($request, $request->validate($rules), $system)])->header('Cache-Control', 'private, no-store');
    }
}
