<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BloodBank\BloodBankQuery;
use App\Http\Requests\BloodBank\SaveBloodProfile;
use App\Http\Requests\BloodBank\SaveDonation;
use App\Services\BloodBank\BloodBankAccess;
use App\Services\BloodBank\BloodBankQueries;
use App\Services\BloodBank\BloodBankReports;
use App\Services\BloodBank\BloodBankWriter;
use App\Services\Catalog\CatalogQueries;
use App\Services\Clinics\ClinicCounts;
use App\Services\Doctors\DoctorAccess;
use Database\Seeders\BloodBankReferenceSeeder;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

#[Group('Blood bank')]
class BloodBankController extends Controller
{
    public function __construct(private BloodBankAccess $access, private BloodBankQueries $queries, private BloodBankWriter $writer) {}

    public function export(BloodBankQuery $request, string $format): Response
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'), 'export');

        return app(BloodBankReports::class)->export($request, $f, $request->validated(), $format);
    }

    public function report(BloodBankQuery $request, string $kind, int $item, string $format): Response
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'), 'export');

        return app(BloodBankReports::class)->export($request, $f, $request->validated(), $format, $kind, $item);
    }

    public function donationReport(BloodBankQuery $request, int $donor, int $donation, string $format): Response
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'), 'export');

        return app(BloodBankReports::class)->export($request, $f, $request->validated(), $format, 'donor', $donor, $donation);
    }

    public function index(BloodBankQuery $request): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->listing($f, $request->validated()) + ['capabilities' => $this->access->capabilities($request->user(), $f)]);
    }

    public function show(BloodBankQuery $request, string $kind, int $item): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->queries->profile($kind, $item, $f['id']), 'capabilities' => $this->access->capabilities($request->user(), $f)]);
    }

    public function store(SaveBloodProfile $request): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'), 'create');
        $id = $this->writer->profile($request, $f, $request->validated('kind'), $request->validated(), null);

        return response()->json(['data' => $this->queries->profile($request->validated('kind'), $id, $f['id'])], 201);
    }

    public function update(SaveBloodProfile $request, string $kind, int $item): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'), 'update');
        $this->writer->profile($request, $f, $kind, $request->validated(), $item);

        return response()->json(['data' => $this->queries->profile($kind, $item, $f['id'])]);
    }

    public function options(BloodBankQuery $request): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'));
        $doctors = in_array('doctors.view', $f['permissions'], true) ? app(DoctorAccess::class)->capabilities($request->user(), $f) : [];
        $components = DB::table('blood_components')->whereNotNull('registration_kind')->where('is_active', true)->get(['id', 'code', 'registration_kind'])->keyBy('registration_kind');

        return response()->json(['data' => ['facility' => array_intersect_key($f, array_flip(['id', 'code', 'name_ar', 'timezone'])), 'capabilities' => $this->access->capabilities($request->user(), $f),
            'can_add_doctor' => ($doctors['create'] ?? false) && ($doctors['link'] ?? false),
            'blood_components' => collect(BloodBankReferenceSeeder::COMPONENTS)->map(function ($definition, $kind) use ($components) {
                $row = $components->get($kind);

                return $row ? ['id' => $row->id, 'code' => $row->code, 'name_ar' => $definition[1]] : null;
            })->filter()->values(),
            'screening_tests' => DB::table('screening_tests')->where('is_active', true)->whereNotNull('blood_bank_analyte')->orderBy('display_order')->orderBy('code')->get(['id', 'code', 'name_ar', 'blood_bank_analyte']),
            'governorates' => DB::table('governorates')->where('country_code', 'SY')->orderBy('name_ar')->get(['id', 'name_ar'])]]);
    }

    public function cities(BloodBankQuery $request): JsonResponse
    {
        $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => DB::table('cities')->where('governorate_id', $request->integer('governorate_id'))->orderBy('name_ar')->get(['id', 'name_ar'])]);
    }

    public function clinics(BloodBankQuery $request): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'));
        $q = DB::table('clinics')->where('facility_id', $f['id'])->where('is_active', true)->whereNull('archived_at');
        $like = BloodBankQueries::like($request->input('search'));
        $page = $q->where(fn ($q) => $q->where('name_ar', 'like', $like)->orWhere('code', 'like', $like))->orderBy('code')->orderBy('id')->paginate(20, ['id', 'code', 'name_ar']);

        return response()->json(['data' => $page->items(), 'meta' => CatalogQueries::meta($page)]);
    }

    public function doctors(BloodBankQuery $request): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'));
        $q = app(ClinicCounts::class)->currentDoctors($f)->where('c.id', $request->integer('clinic_id'));
        $like = BloodBankQueries::like($request->input('search'));
        // Match the clinic directory's grouping: overlapping historical links must
        // not inflate the total or produce empty options pages.
        $page = $q->where(fn ($q) => $q->where('s.full_name', 'like', $like)->orWhere('s.staff_code', 'like', $like))
            ->select('s.id', 's.staff_code as code', 's.full_name as name_ar')->groupBy('s.id', 's.staff_code', 's.full_name')
            ->orderBy('s.staff_code')->orderBy('s.id')->paginate(20);

        return response()->json(['data' => $page->items(), 'meta' => CatalogQueries::meta($page),
            'doctor_types_configured' => DB::table('staff_types')->whereIn('code', config('clinics.doctor_staff_types', []))->where('is_active', true)->exists()]);
    }

    public function patients(BloodBankQuery $request): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'));
        $this->access->patients($request->user(), $f);
        $q = DB::table('patients')->where('status', 'active');
        if (trim($request->input('search') ?? '') === '') {
            $q->whereRaw('1 = 0');
        }
        $like = BloodBankQueries::like($request->input('search'));
        $page = $q->where(fn ($q) => $q->where('patient_code', 'like', $like)->orWhereRaw("REGEXP_REPLACE(CONCAT_WS(' ', first_name, family_name), '[[:space:]]+', ' ') LIKE ?", [$like]))->orderBy('patient_code')->orderBy('id')->paginate(20, ['id', 'patient_code', 'first_name', 'family_name', 'birth_date']);
        $page->setCollection($page->getCollection()->map(fn ($p) => (array) $p + ['code' => $p->patient_code, 'name_ar' => $p->first_name.' '.$p->family_name]));

        return response()->json(['data' => $page->items(), 'meta' => CatalogQueries::meta($page)]);
    }

    public function patient(BloodBankQuery $request, int $patient): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'));
        $this->access->patients($request->user(), $f);
        $row = DB::table('patients')->where('id', $patient)->where('status', 'active')->first(['id', 'patient_code', ...SaveBloodProfile::PERSON]);
        abort_unless($row, 404);

        return response()->json(['data' => (array) $row + ['governorate_name' => DB::table('governorates')->where('id', $row->governorate_id)->value('name_ar'), 'city_name' => DB::table('cities')->where('id', $row->city_id)->value('name_ar')]]);
    }

    public function donations(BloodBankQuery $request, int $donor): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->donations($donor, $f['id'], $request->validated()));
    }

    public function donation(BloodBankQuery $request, int $donor, int $donation): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->queries->donation($donor, $donation, $f['id'])]);
    }

    public function storeDonation(SaveDonation $request, int $donor): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'), 'donations.create');
        $id = $this->writer->donation($request, $f, $donor, $request->validated(), null);

        return response()->json(['data' => $this->queries->donation($donor, $id, $f['id'])], 201);
    }

    public function updateDonation(SaveDonation $request, int $donor, int $donation): JsonResponse
    {
        $f = $this->access->facility($request->user(), $request->integer('facility_id'), 'donations.update');
        $this->writer->donation($request, $f, $donor, $request->validated(), $donation);

        return response()->json(['data' => $this->queries->donation($donor, $donation, $f['id'])]);
    }
}
