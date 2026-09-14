<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dossiers\DossierQueryRequest;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierQueries;
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

    public function visit(DossierQueryRequest $r, int $dossier, int $visit)
    {
        return response()->json(['data' => $this->queries->visit($this->facility($r), $dossier, $visit)]);
    }

    private function facility(DossierQueryRequest $r): array
    {
        return $this->access->facility($r->user(), $r->integer('facility_id'));
    }
}
