<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dossiers\DossierAuditRequest;
use App\Http\Requests\Dossiers\DossierQueryRequest;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierAuditHistory;
use App\Services\Dossiers\DossierPurge;
use App\Services\Dossiers\DossierQueries;
use Symfony\Component\HttpFoundation\Response;
use Dedoc\Scramble\Attributes\Group;

#[Group('Patient dossiers')]
class DossierController extends Controller
{
    public function __construct(private DossierAccess $access, private DossierQueries $queries) {}

    public function index(DossierQueryRequest $r)
    {
        return response()->json($this->queries->listing($this->facility($r), $r->validated()));
    }

    public function show(DossierQueryRequest $r, int $dossier)
    {
        return response()->json(['data' => $this->queries->detail($this->facility($r), $dossier)]);
    }

    public function visits(DossierQueryRequest $r, int $dossier)
    {
        return response()->json($this->queries->visits($this->facility($r), $dossier, $r->validated()));
    }

    public function visitDirectory(DossierQueryRequest $r)
    {
        return response()->json($this->queries->visitDirectory($this->facility($r), $r->validated()));
    }

    public function visit(DossierQueryRequest $r, int $dossier, int $visit)
    {
        return response()->json(['data' => $this->queries->visit($this->facility($r), $dossier, $visit)]);
    }

    public function audit(DossierAuditRequest $r, int $dossier)
    {
        $f = $this->access->facility($r->user(), $r->integer('facility_id'), 'audit');

        return response()->json(app(DossierAuditHistory::class)->listing($f, $dossier, $r->validated()));
    }

    /** Cascade-delete the facility medical context and every association. Requires dossiers.view and dossiers.delete. */
    public function destroy(DossierQueryRequest $r, int $dossier, DossierPurge $purge): Response
    {
        $purge->destroy($r, $this->access->facility($r->user(), $r->integer('facility_id'), 'delete'), $dossier);

        return response()->noContent();
    }

    private function facility(DossierQueryRequest $r): array
    {
        return $this->access->facility($r->user(), $r->integer('facility_id'));
    }
}
