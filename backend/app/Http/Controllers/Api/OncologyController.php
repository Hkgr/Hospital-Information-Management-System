<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dossiers\SaveOncology;
use App\Services\Clinics\ClinicCounts;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierWrites;
use App\Services\Dossiers\OncologyQueries;
use App\Services\Dossiers\OncologyWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OncologyController extends Controller
{
    private function scope(Request $r, string $action = 'view'): array
    {
        $r->validate(['facility_id' => ['required', 'integer', 'min:1']]);
        $f = app(DossierAccess::class)->facility($r->user(), $r->integer('facility_id'), 'treatment.'.$action);
        abort_unless(in_array('dossiers.treatment.view', $f['permissions'], true), 403);

        return $f;
    }

    public function options(Request $r)
    {
        $f = $this->scope($r);
        $date = $r->validate(['date' => ['required', 'date_format:Y-m-d'], 'clinic_id' => ['nullable', 'integer', 'min:1']])['date'];

        return response()->json(['data' => ['modalities' => OncologyQueries::MODALITIES, 'intents' => OncologyQueries::INTENTS,
            'doctors' => $r->filled('clinic_id') ? app(ClinicCounts::class)->currentDoctors(array_replace($f, ['today' => $date]))->where('c.id', $r->integer('clinic_id'))->distinct()->orderBy('s.full_name')->get(['s.id', 's.full_name as name_ar']) : [],
            'funding_sources' => DB::table('funding_sources')->where('is_active', true)->orderBy('display_order')->orderBy('id')->get(['id', 'code', 'name_ar']),
            'periods' => DB::table('reporting_periods')->where('facility_id', $f['id'])->where('status', 'open')->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->orderBy('starts_on')->get(['id', 'starts_on', 'ends_on']),
            'staff' => DB::table('staff as s')->join('clinic_staff as cs', 'cs.staff_id', '=', 's.id')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')->where('c.facility_id', $f['id'])->where('c.is_active', true)->whereNull('c.archived_at')->where('s.is_active', true)->whereNull('s.archived_at')->where('cs.starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('cs.ends_on')->orWhere('cs.ends_on', '>', $date))->distinct()->orderBy('s.full_name')->get(['s.id', 's.full_name as name_ar']), 'today' => $f['today']]]);
    }

    public function index(Request $r, int $dossier)
    {
        $f = $this->scope($r);
        $filters = $r->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'in:10,20,50'], 'status' => ['nullable', 'in:draft,active,paused,needs_review,completed,cancelled']]);

        return response()->json(app(OncologyQueries::class)->listing($f, $dossier, $filters));
    }

    public function show(Request $r, int $dossier, int $plan)
    {
        return response()->json(['data' => app(OncologyQueries::class)->plan($this->scope($r), $dossier, $plan)]);
    }

    public function sessions(Request $r, int $dossier)
    {
        $f = $this->scope($r);
        $filters = $r->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'in:10,20,50'], 'plan_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:scheduled,rescheduled,completed,missed,cancelled,referred']]);

        return response()->json(app(OncologyQueries::class)->sessions($f, $dossier, $filters));
    }

    public function session(Request $r, int $dossier, int $session)
    {
        $f = $this->scope($r);
        app(DossierWrites::class)->dossier($f, $dossier, false);
        $row = DB::table('oncology_sessions')->where('id', $session)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->first();
        abort_unless($row, 404);

        return response()->json(['data' => $row]);
    }

    public function doses(Request $r, int $dossier, int $visit)
    {
        return response()->json(['data' => app(OncologyQueries::class)->doses($this->scope($r), $dossier, $visit)]);
    }

    public function savePlan(SaveOncology $r, int $dossier, ?int $plan = null)
    {
        $f = $this->scope($r, $plan ? 'update' : 'create');
        $id = app(OncologyWriter::class)->savePlan($r, $f, $dossier, $r->validated(), $plan);

        return response()->json(['data' => app(OncologyQueries::class)->plan($f, $dossier, $id)], $plan ? 200 : 201);
    }

    public function status(SaveOncology $r, int $dossier, int $plan)
    {
        $f = $this->scope($r, $r->input('status') === 'active' ? 'activate' : 'status');
        app(OncologyWriter::class)->status($r, $f, $dossier, $plan, $r->validated());

        return response()->json(['data' => app(OncologyQueries::class)->plan($f, $dossier, $plan)]);
    }

    public function schedule(SaveOncology $r, int $dossier, int $plan)
    {
        $f = $this->scope($r, 'schedule');
        app(OncologyWriter::class)->schedule($r, $f, $dossier, $plan, $r->validated());

        return response()->json(['data' => ['id' => $plan]], 201);
    }

    public function updateSession(SaveOncology $r, int $dossier, int $session)
    {
        $id = app(OncologyWriter::class)->session($r, $this->scope($r, 'schedule'), $dossier, $session, $r->validated());

        return response()->json(['data' => ['id' => $id]]);
    }

    public function administer(SaveOncology $r, int $dossier, int $visit, ?int $dose = null)
    {
        $void = $r->operation() === 'void';
        $f = $this->scope($r, $void ? 'void' : ($dose ? 'correct' : 'administer'));
        $id = app(OncologyWriter::class)->administer($r, $f, $dossier, $visit, $r->validated(), $dose, $void);

        return response()->json(['data' => ['id' => $id]], $dose ? 200 : 201);
    }

    public function dispense(SaveOncology $r, int $dossier, int $visit, ?int $dispensing = null)
    {
        $void = $r->operation() === 'void';
        $f = $this->scope($r, $void ? 'void' : ($dispensing ? 'correct' : 'dispense'));
        $id = app(OncologyWriter::class)->dispense($r, $f, $dossier, $visit, $r->validated(), $dispensing, $void);

        return response()->json(['data' => ['id' => $id]], $dispensing ? 200 : 201);
    }
}
