<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BloodBank\BloodEventQueries;
use App\Services\Catalog\CatalogQueries;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierWrites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DossierBloodBenefitController extends Controller
{
    public function context(Request $r, int $dossier, int $visit)
    {
        $r->validate(['facility_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $access = app(DossierAccess::class);
        $f = $access->facility($r->user(), $r->integer('facility_id'), 'services.update');
        $access->facility($r->user(), $f['id'], 'visits.view');
        $d = app(DossierWrites::class)->dossier($f, $dossier, false);
        $v = DB::table('visits')->where('id', $visit)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->where('patient_id', $d['patient_id'])->whereNull('voided_at')->first();
        abort_unless($v, 404);
        abort_unless($v->status === 'draft', 409);
        $person = DB::table('blood_bank_people')->where('facility_id', $f['id'])->where('patient_id', $d['patient_id'])->first();
        if ($r->route('issues')) {
            $q = app(BloodEventQueries::class)->events($f['id'], ['person_id' => $person?->id, 'available_issues' => true, 'search' => $r->input('search')]);
            $page = $q->when(! $person, fn ($q) => $q->whereRaw('1=0'))->orderByDesc('e.occurred_on')->orderByDesc('e.id')->paginate(20);

            return response()->json(['data' => $page->items(), 'meta' => CatalogQueries::meta($page)]);
        }
        $p = DB::table('patients')->where('id', $d['patient_id'])->first(['id', 'patient_code', 'first_name', 'family_name', 'phone']);

        return response()->json(['data' => [
            'patient' => ['patient_id' => $p->id, 'name' => $p->first_name.' '.$p->family_name, 'code' => $p->patient_code, 'patient_code' => $p->patient_code, 'person' => ['phone' => $p->phone], 'blood_group' => $person?->blood_group, 'rh' => $person?->rh],
            'options' => ['facility' => ['id' => $f['id'], 'code' => $f['code'], 'name_ar' => $f['name_ar']], 'capabilities' => ['issue_create' => true, 'transfusion_create' => true], 'can_add_doctor' => false, 'blood_components' => DB::table('blood_components')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']), 'screening_tests' => [], 'governorates' => []],
        ]]);
    }
}
