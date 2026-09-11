<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinics\ClinicVersionRequest;
use App\Http\Requests\Directory\LinkOptionsRequest;
use App\Http\Requests\Doctors\DoctorQueryRequest;
use App\Http\Requests\Doctors\SaveDoctorRequest;
use App\Services\Doctors\DoctorAccess;
use App\Services\Doctors\DoctorQueries;
use App\Services\Doctors\DoctorReports;
use App\Services\Doctors\DoctorWriter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

#[Group('Doctors')]
class DoctorController extends Controller
{
    public function __construct(private DoctorAccess $access, private DoctorQueries $queries, private DoctorWriter $writer) {}

    /** Global professional directory; counts/links are limited to the authorized facility. Requires doctors.view. */
    public function index(DoctorQueryRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->list($facility, $request->validated()));
    }

    /** Professional details only; never exposes medical records, funding data or login accounts. */
    public function show(DoctorQueryRequest $request, int $doctor): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->queries->find($facility, $doctor)]);
    }

    /** Explicit global doctors.directory.create grant; clinic deltas additionally require facility doctors.link. */
    public function store(SaveDoctorRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));
        $id = $this->writer->save($request, $facility, $request->validated(), null);

        return response()->json(['data' => $this->queries->find($facility, $id)], 201);
    }

    /** Global doctors.directory.update grant and matching lock_version. Only explicit clinic deltas change links. */
    public function update(SaveDoctorRequest $request, int $doctor): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));
        $this->writer->save($request, $facility, $request->validated(), $doctor);

        return response()->json(['data' => $this->queries->find($facility, $doctor)]);
    }

    /** Facility doctors.link only. Directory fields are prohibited; historical/out-of-facility links survive. */
    public function updateClinics(SaveDoctorRequest $request, int $doctor): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'link');
        $this->writer->save($request, $facility, $request->validated(), $doctor, true);

        return response()->json(['data' => $this->queries->find($facility, $doctor)]);
    }

    /** Global doctors.directory.delete grant. Any reference/history returns DOCTOR_REFERENCED (409). */
    public function destroy(ClinicVersionRequest $request, int $doctor): Response
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));
        $this->writer->delete($request, $facility, $doctor, $request->integer('lock_version'));

        return response()->noContent();
    }

    /** Separate GLOBAL deactivation, requires doctors.directory.update; preserves all history. */
    public function deactivate(ClinicVersionRequest $request, int $doctor): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));
        $this->writer->deactivate($request, $facility, $doctor, $request->integer('lock_version'));

        return response()->json(['data' => $this->queries->find($facility, $doctor)]);
    }

    /** Paginated distinct active clinics with a current half-open period in this facility. */
    public function clinics(DoctorQueryRequest $request, int $doctor): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->clinics($facility, $request->validated(), $doctor));
    }

    /** Active clinic choices, with current linkage when doctor_id is provided. */
    public function clinicOptions(LinkOptionsRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->clinics($facility, $request->validated()));
    }

    /** Active configured types/specialties and dynamic scoped capabilities. Empty type configuration fails closed. */
    public function options(DoctorQueryRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));
        $types = DB::table('staff_types')->whereIn('code', config('clinics.doctor_staff_types'))->where('is_active', true)->orderBy('name_ar')->orderBy('id')->get(['id', 'code', 'name_ar']);

        return response()->json(['data' => [
            'staff_types' => $types,
            'specialties' => DB::table('specialties')->where('is_active', true)->orderBy('display_order')->orderBy('id')->get(['id', 'name_ar']),
            'doctor_types_configured' => $types->isNotEmpty(),
            'capabilities' => $this->access->capabilities($request->user(), $facility),
        ]]);
    }

    /** All filtered results/selected columns, subject to documented limits; facility doctors.export. */
    public function export(DoctorQueryRequest $request, string $format, DoctorReports $reports): Response
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'export');

        return $reports->export($request, $facility, $request->validated(), $format);
    }

    /** Private complete PDF doctor report, facility counts/links only; no patient identities. */
    public function report(DoctorQueryRequest $request, int $doctor, DoctorReports $reports): Response
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'export');

        return $reports->export($request, $facility, [], 'pdf', $doctor);
    }
}
