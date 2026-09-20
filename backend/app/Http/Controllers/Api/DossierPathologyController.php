<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dossiers\SaveDiagnosticAssessment;
use App\Http\Requests\Dossiers\SavePathology;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierPathology;
use Illuminate\Http\Request;

class DossierPathologyController extends Controller
{
    private function scope(Request $r, string $action = 'view'): array
    {
        $r->validate(['facility_id' => ['required', 'integer', 'min:1']]);

        return app(DossierAccess::class)->facility($r->user(), $r->integer('facility_id'), $action);
    }

    public function index(Request $r, int $dossier, ?int $visit = null)
    {
        $f = $this->scope($r);
        $filters = $r->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'in:10,20,50'], 'status' => ['nullable', 'in:requested,specimen_collected,pending_result,completed,unavailable,cancelled'], 'source' => ['nullable', 'in:internal,external'], 'search' => ['nullable', 'string', 'max:200'], 'evidence_only' => ['sometimes', 'boolean']]);

        return response()->json(app(DossierPathology::class)->listing($f, $dossier, $filters, $visit));
    }

    public function assessment(Request $r, int $dossier, int $visit)
    {
        return response()->json(['data' => app(DossierPathology::class)->assessment($this->scope($r), $dossier, $visit)]);
    }

    public function show(Request $r, int $dossier, int $visit, int $pathology)
    {
        return response()->json(['data' => app(DossierPathology::class)->show($this->scope($r), $dossier, $visit, $pathology)]);
    }

    public function saveAssessment(SaveDiagnosticAssessment $r, int $dossier, int $visit)
    {
        $f = $this->scope($r, 'assessment.update');
        app(DossierPathology::class)->saveAssessment($r, $f, $dossier, $visit, $r->validated());

        return response()->json(['data' => app(DossierPathology::class)->assessment($f, $dossier, $visit)]);
    }

    public function save(SavePathology $r, int $dossier, int $visit, ?int $pathology = null)
    {
        $void = $r->routeIs('dossiers.pathology.void');
        $f = $this->scope($r, 'pathology.'.($void ? 'void' : ($pathology ? 'update' : 'create')));
        $id = app(DossierPathology::class)->save($r, $f, $dossier, $visit, $r->validated(), $pathology, $void);

        return response()->json(['data' => ['id' => $id]], $pathology ? 200 : 201);
    }
}
