<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinics\ClinicQueryRequest;
use App\Http\Requests\Clinics\ClinicVersionRequest;
use App\Http\Requests\Clinics\SaveClinicRequest;
use App\Http\Requests\Directory\LinkOptionsRequest;
use App\Services\Clinics\ClinicAccess;
use App\Services\Clinics\ClinicQueries;
use App\Services\Clinics\ClinicReports;
use App\Services\Clinics\ClinicWriter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

#[Group('Clinics')]
class ClinicController extends Controller
{
    public function __construct(private ClinicAccess $access, private ClinicQueries $queries, private ClinicWriter $writer) {}

    /** List clinics. Requires clinics.view in the requested facility. */
    public function index(ClinicQueryRequest $request): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->list($facility, $request->validated()));
    }

    /** Read one clinic; an id in another facility is indistinguishable from a missing id. */
    public function show(ClinicQueryRequest $request, int $clinic): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->queries->find($facility, $clinic)]);
    }

    /** Create a clinic, optionally with existing doctors. Requires clinics.create and clinics.view. */
    public function store(SaveClinicRequest $request): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'create');
        $id = $this->writer->save($request, $facility, $request->validated(), null);

        return response()->json(['data' => $this->queries->find($facility, $id)], 201);
    }

    /** Update with lock_version and explicit doctor_add_ids / doctor_remove_ids; omitted links survive. */
    public function update(SaveClinicRequest $request, int $clinic): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'update');
        $this->writer->save($request, $facility, $request->validated(), $clinic);

        return response()->json(['data' => $this->queries->find($facility, $clinic)]);
    }

    /** Delete only unreferenced clinics. Requires clinics.delete; references or stale versions return 409. */
    public function destroy(ClinicVersionRequest $request, int $clinic): Response
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'delete');
        $this->writer->delete($request, $facility, $clinic, $request->integer('lock_version'));

        return response()->noContent();
    }

    /** Separate deactivation action. Requires clinics.update, preserves medical history. */
    public function deactivate(ClinicVersionRequest $request, int $clinic): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'update');
        $this->writer->deactivate($request, $facility, $clinic, $request->integer('lock_version'));

        return response()->json(['data' => $this->queries->find($facility, $clinic)]);
    }

    /** Paginated current active doctors, with code, name, specialties and starts_on only. */
    public function doctors(ClinicQueryRequest $request, int $clinic): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->doctors($facility, $request->validated(), $clinic));
    }

    /** Search eligible existing doctors from the global staff directory, gated by facility clinics.view. */
    public function doctorOptions(LinkOptionsRequest $request): JsonResponse
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->doctors($facility, $request->validated()) + ['doctor_types_configured' => count(config('clinics.doctor_staff_types')) > 0]);
    }

    /** Active specialty choices, gated by facility clinics.view. */
    public function specialties(ClinicQueryRequest $request): JsonResponse
    {
        $this->access->authorize($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => DB::table('specialties')->where('is_active', true)->orderBy('display_order')->orderBy('name_ar')->orderBy('id')->get(['id', 'name_ar'])]);
    }

    /** Export all filtered rows and selected columns, up to the documented safety limits. Requires clinics.export and clinics.view. */
    public function export(ClinicQueryRequest $request, string $format, ClinicReports $reports): Response
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'export');

        return $reports->export($request, $facility, $request->validated(), $format);
    }

    /** Private PDF clinic report; no patient identities. Requires clinics.export and clinics.view. */
    public function report(ClinicQueryRequest $request, int $clinic, ClinicReports $reports): Response
    {
        $facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'export');

        return $reports->export($request, $facility, ['facility_id' => $facility['id']], 'pdf', $clinic);
    }
}
