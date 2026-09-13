<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CatalogEventsRequest;
use App\Http\Requests\Catalog\CatalogQueryRequest;
use App\Http\Requests\Catalog\SaveCatalogRequest;
use App\Http\Requests\Catalog\SaveCategoryRequest;
use App\Http\Requests\Clinics\ClinicVersionRequest;
use App\Services\Catalog\CatalogAccess;
use App\Services\Catalog\CatalogBeneficiaries;
use App\Services\Catalog\CatalogQueries;
use App\Services\Catalog\CatalogReports;
use App\Services\Catalog\CatalogWriter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

#[Group('Services and procedures')]
class CatalogController extends Controller
{
    public function __construct(private CatalogAccess $access, private CatalogQueries $queries, private CatalogWriter $writer) {}

    public function context(Request $request): JsonResponse
    {
        return response()->json(['data' => ['facility' => $this->access->context($request->user())]]);
    }

    public function createCategory(SaveCategoryRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->writer->createCategory($request, $facility, $request->validated())], 201);
    }

    public function events(CatalogEventsRequest $request, string $kind, int $item): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'beneficiaries');
        $this->queries->find($facility, $kind, $item);

        return response()->json(app(CatalogBeneficiaries::class)->presentations($facility, $kind, $item, $request->validated()));
    }

    /** Global definitions; counts only from the authorized facility. Requires catalog.view. */
    public function index(CatalogQueryRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->listing($facility, $request->validated()) + ['capabilities' => $this->access->capabilities($request->user(), $facility)]);
    }

    public function show(CatalogQueryRequest $request, string $kind, int $item): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => $this->queries->find($facility, $kind, $item), 'capabilities' => $this->access->capabilities($request->user(), $facility)]);
    }

    public function store(SaveCatalogRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));
        $kind = $request->validated('kind');
        $id = $this->writer->save($request, $facility, $kind, $request->validated(), null);

        return response()->json(['data' => $this->queries->find($facility, $kind, $id)], 201);
    }

    public function update(SaveCatalogRequest $request, string $kind, int $item): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));
        $this->writer->save($request, $facility, $kind, $request->validated(), $item);

        return response()->json(['data' => $this->queries->find($facility, $kind, $item)]);
    }

    public function deletionPreview(CatalogQueryRequest $request, string $kind, int $item): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));
        $this->access->directory($request->user(), $facility, 'delete');
        $row = $this->queries->find($facility, $kind, $item);
        $references = $this->writer->references($kind, $item);

        return response()->json(['data' => ['action' => $references ? 'archive' : 'delete', 'has_references' => $references, 'lock_version' => $row['lock_version'], 'archived' => $row['archived_at'] !== null]]);
    }

    public function lifecycle(ClinicVersionRequest $request, string $kind, int $item): Response
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));
        $action = $request->isMethod('DELETE') ? 'delete' : basename($request->path());
        $this->writer->apply($request, $facility, $kind, $item, $request->integer('lock_version'), $action);

        return $action === 'delete' ? response()->noContent() : response()->json(['data' => $this->queries->find($facility, $kind, $item)]);
    }

    /** Minimum patient identity, only with catalog.beneficiaries in this facility. */
    public function beneficiaries(CatalogQueryRequest $request, string $kind, int $item): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'beneficiaries');
        $this->queries->find($facility, $kind, $item);

        return response()->json(app(CatalogBeneficiaries::class)->patients($facility, $kind, $item, $request->validated()));
    }

    /** Audit entries in the selected facility only; no patient values. Requires catalog.audit. */
    public function history(CatalogQueryRequest $request, string $kind, int $item): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'audit');
        $this->queries->find($facility, $kind, $item);
        $page = DB::table('audit_logs')->where('facility_id', $facility['id'])->where('entity_type', $kind)->where('entity_id', $item)
            ->select('id', 'event', 'occurred_at', 'old_values', 'new_values')->orderByDesc('id')->paginate($request->integer('per_page', 20));

        return response()->json(['data' => $page->items(), 'meta' => CatalogQueries::meta($page)]);
    }

    /** Active category/type choices; existing inactive choices can be retained by editing their current record. */
    public function classifications(CatalogQueryRequest $request): JsonResponse
    {
        $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json(['data' => ['categories' => DB::table('service_categories')->where('is_active', true)->orderBy('name_ar')->orderBy('id')->get(['id', 'name_ar']),
            'procedure_types' => DB::table('procedure_types')->where('is_active', true)->orderBy('name_ar')->orderBy('id')->get(['id', 'name_ar'])]]);
    }

    /** Choices for new registrations: always active and unarchived, even if another status is requested. */
    public function options(CatalogQueryRequest $request): JsonResponse
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'));

        return response()->json($this->queries->listing($facility, array_replace($request->validated(), ['status' => 'active'])));
    }

    public function export(CatalogQueryRequest $request, string $format): Response
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'export');

        return app(CatalogReports::class)->export($request, $facility, $request->validated(), $format);
    }

    public function report(CatalogQueryRequest $request, string $kind, int $item): Response
    {
        $facility = $this->access->facility($request->user(), $request->integer('facility_id'), 'export');

        return app(CatalogReports::class)->export($request, $facility, $request->validated(), 'pdf', $kind, $item);
    }
}
