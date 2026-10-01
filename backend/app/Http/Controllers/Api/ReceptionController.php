<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dossiers\RegisterReception;
use App\Services\Auth\GlobalAccess;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierPersonalWriter;
use App\Services\Dossiers\DossierWorkflowActions;
use App\Services\Dossiers\ReceptionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceptionController extends Controller
{
    public function options(Request $r)
    {
        $f = $this->scope($r);
        $global = app(GlobalAccess::class);

        return response()->json(['data' => ['today' => $f['today'], 'can_manage_global_access' => in_array('users.view', $f['permissions'], true) && $global->allows($r->user(), 'users.global.view'), 'can_search' => $global->allows($r->user(), 'patients.basic.search'), 'can_create_patient' => $global->allows($r->user(), 'patients.basic.create'), 'can_register' => in_array('patient_cards.register', $f['permissions'], true)]]);
    }

    private function scope(Request $r, string $action = 'view'): array
    {
        $r->validate(['facility_id' => ['required', 'integer', 'min:1']]);

        return app(ReceptionAccess::class)->facility($r->user(), $r->integer('facility_id'), $action);
    }

    public function search(Request $r)
    {
        $f = $this->scope($r);
        app(ReceptionAccess::class)->patients($r->user(), 'search');
        $r->validate(['search' => ['required', 'string', 'max:100']]);
        $term = trim(preg_replace('/\s+/u', ' ', $r->string('search')));
        if (mb_strlen($term) < 3) {
            return response()->json(['data' => []]);
        }
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $rows = DB::table('patients as p')->where('p.status', 'active')->where(fn ($q) => $q->where('p.patient_code', $term)->orWhere('p.national_id', $term)->orWhereExists(fn ($a) => $a->selectRaw('1')->from('patients as alias')->whereColumn('alias.merged_into_id', 'p.id')->where('alias.patient_code', $term))->orWhereRaw("REGEXP_REPLACE(CONCAT_WS(' ', p.first_name, p.family_name), '[[:space:]]+', ' ') LIKE ? ESCAPE '!'", [$like]))
            ->select('p.id', 'p.patient_code as code', 'p.first_name', 'p.family_name', 'p.birth_date', 'p.gender')
            ->selectSub(DB::table('patient_dossiers as d')->whereColumn('d.patient_id', 'p.id')->where('d.facility_id', $f['id'])->select('d.id')->limit(1), 'dossier_id')->orderBy('p.first_name')->orderBy('p.id')->limit(10)->get();

        return response()->json(['data' => $rows]);
    }

    public function show(Request $r, int $dossier)
    {
        return response()->json(['data' => $this->summary($this->scope($r), $dossier)]);
    }

    public function store(RegisterReception $r)
    {
        $f = $this->scope($r, 'register');
        $id = app(DossierPersonalWriter::class)->registerReception($r, $f, $r->validated());

        return response()->json(['data' => $this->summary($f, $id)], 201);
    }

    private function summary(array $f, int $id): object
    {
        $row = DB::table('patient_dossiers as d')->join('patients as p', 'p.id', '=', 'd.patient_id')->where('d.facility_id', $f['id'])->where('d.id', $id)->whereIn('d.status', ['draft', 'active'])
            ->first(['d.id', 'p.id as patient_id', 'p.patient_code as code', 'p.first_name', 'p.family_name', 'p.birth_date', 'p.gender', 'd.opening_date', 'd.status', 'd.lock_version', 'd.registration_visit_id']);
        abort_unless($row, 404);
        $row->workflow = null;
        if (in_array('dossiers.medical.view', $f['permissions'], true)) {
            $medical = app(DossierAccess::class)->facility(request()->user(), $f['id']);
            $row->workflow = app(DossierWorkflowActions::class)->forDossiers($medical, [$row])[$row->id]['workflow'];
        }

        return $row;
    }
}
