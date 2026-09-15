<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dossiers\SaveDossierSection;
use App\Services\Catalog\CatalogQueries;
use App\Services\Clinics\ClinicCounts;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierMedicalWriter;
use App\Services\Dossiers\DossierPersonalWriter;
use App\Services\Dossiers\DossierVisitWriter;
use App\Services\Dossiers\DossierWizardQueries;
use App\Services\Dossiers\DossierWorkflowActions;
use App\Services\Dossiers\DossierWrites;
use Database\Seeders\DossierOutcomeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DossierWizardController extends Controller
{
    public function __construct(private DossierAccess $access, private DossierWizardQueries $queries) {}

    public function personal(SaveDossierSection $r): JsonResponse
    {
        $dossier = $r->route('dossier') ? (int) $r->route('dossier') : null;
        $f = $this->access->facility($r->user(), $r->integer('facility_id'), $dossier ? 'personal.update' : 'create');
        $id = app(DossierPersonalWriter::class)->save($r, $f, $r->validated(), $dossier);

        return response()->json(['data' => $this->queries->snapshot($f, $id)], $dossier ? 200 : 201);
    }

    public function medical(SaveDossierSection $r, int $dossier): JsonResponse
    {
        $f = $this->access->facility($r->user(), $r->integer('facility_id'), 'medical.update');
        app(DossierMedicalWriter::class)->save($r, $f, $dossier, $r->validated());

        return response()->json(['data' => $this->queries->snapshot($f, $dossier)]);
    }

    public function visit(SaveDossierSection $r): JsonResponse
    {
        $dossier = (int) $r->route('dossier');
        $visit = $r->route('visit') ? (int) $r->route('visit') : null;
        $f = $this->access->facility($r->user(), $r->integer('facility_id'), $visit ? 'visits.update' : 'visits.create');
        $saved = app(DossierVisitWriter::class)->save($r, $f, $dossier, $r->validated(), $visit);

        return response()->json(['data' => $this->queries->snapshot($f, $dossier, $saved)], $visit ? 200 : 201);
    }

    public function progress(Request $r, int $dossier): JsonResponse
    {
        $f = $this->scope($r);

        return response()->json(['data' => $this->queries->snapshot($f, $dossier)]);
    }

    private function scope(Request $r): array
    {
        $r->validate(['facility_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:200'], 'page' => ['sometimes', 'integer', 'min:1'], 'clinic_id' => ['sometimes', 'integer', 'min:1'], 'governorate_id' => ['sometimes', 'integer', 'min:1'], 'visit_date' => ['sometimes', 'date_format:Y-m-d']]);

        return $this->access->facility($r->user(), $r->integer('facility_id'));
    }

    public function options(Request $r): JsonResponse
    {
        $f = $this->scope($r);
        $caps = $f['capabilities'];

        return response()->json(['data' => ['capabilities' => $caps, 'creation' => DossierWorkflowActions::creation($caps), 'today' => $f['today'], 'governorates' => DB::table('governorates')->where('country_code', 'SY')->orderBy('name_ar')->get(['id', 'name_ar']), 'visit_types' => DB::table('visit_types')->where('is_active', true)->orderBy('display_order')->orderBy('code')->get(['id', 'code', 'name_ar'])]]);
    }

    public function lookup(Request $r): JsonResponse
    {
        $f = $this->scope($r);
        $kind = $r->route('lookup');
        $search = trim(preg_replace('/\s+/u', ' ', $r->input('search', '') ?? ''));
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        if ($kind === 'patients') {
            $this->access->global($r->user(), 'patients.search');
            abort_unless(in_array('dossiers.create', $f['permissions'], true), 403);
            $q = DB::table('patients')->where('status', 'active')->when($search === '', fn ($q) => $q->whereRaw('1=0'))
                ->where(fn ($q) => $q->whereRaw("patient_code LIKE ? ESCAPE '!'", [$like])->orWhereRaw("REGEXP_REPLACE(CONCAT_WS(' ', first_name, family_name), '[[:space:]]+', ' ') LIKE ? ESCAPE '!'", [$like]))
                ->select('id', 'patient_code as code')->selectRaw("CONCAT_WS(' ', first_name, family_name) as name_ar")
                ->selectSub(DB::table('patient_dossiers')->whereColumn('patient_id', 'patients.id')->where('facility_id', $f['id'])->select('id')->limit(1), 'dossier_id');
        } elseif ($kind === 'doctors') {
            $date = $r->input('visit_date', $f['today']);
            $q = app(ClinicCounts::class)->currentDoctors(array_replace($f, ['today' => $date]))->where('c.id', $r->integer('clinic_id'))
                ->where(fn ($q) => $q->whereRaw("s.full_name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("s.staff_code LIKE ? ESCAPE '!'", [$like]))
                ->select('s.id', 's.staff_code as code', 's.full_name as name_ar')->distinct();
        } else {
            $table = ['cities' => 'cities', 'clinics' => 'clinics', 'diagnoses' => 'diagnoses', 'services' => 'services', 'procedures' => 'procedures', 'medications' => 'medications', 'outcomes' => 'visit_results'][$kind];
            $q = DB::table($table)->where(function ($q) use ($kind, $like) {
                $q->whereRaw("name_ar LIKE ? ESCAPE '!'", [$like]);
                if ($kind !== 'cities') {
                    $q->orWhereRaw("code LIKE ? ESCAPE '!'", [$like]);
                }
            });
            if ($kind === 'cities') {
                $q->where('governorate_id', $r->integer('governorate_id'))->select('id', 'name_ar')->selectRaw('NULL as code');
            } else {
                $q->where('is_active', true)->select('id', 'code', 'name_ar');
                if (in_array($kind, ['services', 'procedures'])) {
                    $q->whereNull('archived_at');
                }
                if ($kind === 'outcomes') {
                    $q->whereIn('code', array_keys(DossierOutcomeSeeder::OUTCOMES));
                }
                if ($kind === 'clinics') {
                    $q->where('facility_id', $f['id'])->whereNull('archived_at');
                }
            }
        }
        $page = $q->orderBy('name_ar')->orderBy('id')->paginate(20);

        return response()->json(['data' => $page->items(), 'meta' => CatalogQueries::meta($page)]);
    }

    public function diagnosis(Request $r): JsonResponse
    {
        $f = $this->scope($r);
        $this->access->global($r->user(), 'diagnoses.create');
        abort_unless(in_array('dossiers.visits.create', $f['permissions'], true) || in_array('dossiers.visits.update', $f['permissions'], true), 403);
        $data = $r->validate(['facility_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:50'], 'name_ar' => ['required', 'string', 'max:200']], ['required' => 'هذا الحقل مطلوب.', 'max' => 'القيمة تتجاوز الحد المسموح.']);
        try {
            $id = app(DossierWrites::class)->once($r, $f, $data, 'diagnosis:new', function () use ($r, $f, $data) {
                // Serialize wizard directory writers even when neither code nor name exists yet.
                DB::table('number_sequences')->insertOrIgnore(['sequence_key' => 'diagnosis_directory', 'scope_key' => 'global', 'period_key' => 'all']);
                DB::table('number_sequences')->where('sequence_key', 'diagnosis_directory')->where('scope_key', 'global')->where('period_key', 'all')->lockForUpdate()->first();
                $name = trim(preg_replace('/\s+/u', ' ', $data['name_ar']));
                if (DB::table('diagnoses')->where('code', $data['code'])->exists()) {
                    throw ValidationException::withMessages(['code' => 'كود التشخيص مستخدم.']);
                }
                if (DB::table('diagnoses')->whereRaw("TRIM(REGEXP_REPLACE(name_ar, '[[:space:]]+', ' ')) = ?", [$name])->exists()) {
                    throw ValidationException::withMessages(['name_ar' => 'هذا الاسم موجود في دليل التشخيصات.']);
                }
                $id = DB::table('diagnoses')->insertGetId(['code' => $data['code'], 'name_ar' => $name, 'created_at' => now(), 'updated_at' => now()]);
                app(DossierWrites::class)->audit($r, $f, 'diagnosis', $id, null, ['code' => $data['code'], 'name_ar' => $name]);

                return $id;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'كود التشخيص مستخدم.']);
            }
            throw $e;
        }

        return response()->json(['data' => DB::table('diagnoses')->where('id', $id)->first(['id', 'code', 'name_ar'])], 201);
    }
}
