<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Directory\ClinicalAssignmentDates;
use Illuminate\Http\Request;

class ClinicalAssignmentController extends Controller
{
    public function update(Request $request, int $parent, int $assignment)
    {
        $data = $request->validate(['facility_id' => ['required', 'integer', 'min:1'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'clinic_lock_version' => ['required', 'integer', 'min:1'], 'staff_lock_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:255']]);

        return response()->json(['data' => app(ClinicalAssignmentDates::class)->update($request, $request->routeIs('doctors.assignments.update'), $parent, $assignment, $data)]);
    }
}
