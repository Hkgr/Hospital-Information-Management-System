<?php

namespace App\Services\Dossiers\Imports;

use App\Http\Requests\Dossiers\SaveDossierSection;
use App\Http\Requests\Dossiers\SaveVisitClinical;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierClinicalContext;
use App\Services\Dossiers\DossierClinicalWriter;
use App\Services\Dossiers\DossierMedicalWriter;
use App\Services\Dossiers\DossierPersonalWriter;
use App\Services\Dossiers\DossierVisitWriter;
use App\Services\Dossiers\DossierWrites;
use App\Services\Dossiers\PatientCardCodes;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Adapts explicit source facts to the existing clinical validators and writers. */
class ImportBundle
{
    private array $referenceIds = [];

    private array $references = [];

    private array $checkedContexts = [];

    private array $globalPermissions = [];

    private array $identities = [];

    private array $patients = [];

    private array $dossiers = [];

    private array $sourcePatients = [];

    private array $paperFiles = [];

    private array $governorates = [];

    private array $cities = [];

    public function prime(array $rows, array $f, bool $lock): void
    {
        $fields = ['Diagnoses' => ['diagnoses', 'diagnosis_id'], 'Services' => ['services', 'catalog_id'], 'Procedures' => ['procedures', 'catalog_id'], 'Medications' => ['medications', 'medication_id']];
        foreach ($rows as $row) {
            if (isset($fields[$row['sheet']])) {
                [$table, $field] = $fields[$row['sheet']];
                $id = $row['data'][$field] ?? null;
                if (is_numeric($id)) {
                    $this->referenceIds[$table][(int) $id] = (int) $id;
                }
            }
        }
        $people = array_column(array_values(array_filter($rows, fn ($r) => $r['sheet'] === 'Patients')), 'data');
        $codes = array_values(array_unique(array_filter([...array_column($people, 'patient_code'), ...array_column($people, 'legacy_code')])));
        $canonical = DB::table('patients')->whereIn('patient_code', $codes)->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $aliases = DB::table('patient_dossiers')->whereIn('code', $codes)->when($lock, fn ($q) => $q->lockForUpdate())->get(['patient_id', 'code']);
        $sources = DB::table('dossier_import_sources as s')->join('dossier_import_rows as r', 'r.id', '=', 's.row_id')->join('patient_dossiers as d', 'd.id', '=', 'r.dossier_id')->where('s.facility_id', $f['id'])->where('s.sheet', 'Patients')->whereIn('s.source_record_id', array_column($people, 'source_record_id'))->when($lock, fn ($q) => $q->lockForUpdate())->get(['s.source_record_id', 's.fingerprint', 'd.patient_id']);
        foreach ($canonical as $p) {
            $this->identities[self::codeKey($p->patient_code)][] = $p->id;
        }
        foreach ($aliases as $a) {
            $this->identities[self::codeKey($a->code)][] = $a->patient_id;
        }
        foreach ($sources as $source) {
            $this->sourcePatients[$source->source_record_id] = $source;
        }
        $ids = $canonical->pluck('id')->merge($aliases->pluck('patient_id'))->merge($sources->pluck('patient_id'))->unique()->all();
        $this->patients = DB::table('patients')->whereIn('id', $ids)->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id')->all();
        $this->dossiers = DB::table('patient_dossiers')->where('facility_id', $f['id'])->whereIn('patient_id', $ids)->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('patient_id')->all();
        $this->paperFiles = array_fill_keys(DB::table('patients')->whereIn('paper_file_number', array_filter(array_column($people, 'paper_file_number')))->pluck('paper_file_number')->map(fn ($v) => self::codeKey($v))->all(), true);
        $this->governorates = array_fill_keys(DB::table('governorates')->where('country_code', 'SY')->whereIn('id', array_filter(array_column($people, 'governorate_id')))->pluck('id')->all(), true);
        $this->cities = DB::table('cities')->whereIn('id', array_filter(array_column($people, 'city_id')))->pluck('governorate_id', 'id')->all();
    }

    private static function codeKey(string $code): string
    {
        return mb_strtolower(trim($code), 'UTF-8');
    }

    private function facilityPermission(Request $r, array $f, string $action): void
    {
        if (! in_array('dossiers.'.$action, $f['permissions'], true)) {
            app(DossierAccess::class)->facility($r->user(), $f['id'], $action);
        }
    }

    private function globalPermission(Request $r, string $permission): void
    {
        if (! isset($this->globalPermissions[$permission])) {
            app(DossierAccess::class)->global($r->user(), $permission);
            $this->globalPermissions[$permission] = true;
        }
    }

    public function prepare(Request $r, array $f, array $rows, bool $lock = false): array
    {
        $this->require(count($rows) <= 500, 'local_patient_ref', 'تتجاوز مجموعة المريض 500 صف؛ قسّم الزيارات على دفعات مع الحفاظ على مراجع المصادر.');
        $patientRows = array_values(array_filter($rows, fn ($row) => $row['sheet'] === 'Patients'));
        $this->require(count($patientRows) === 1, 'local_patient_ref', 'يلزم صف مريض واحد فقط لكل مرجع محلي.');
        $p = $patientRows[0]['data'];
        Validator::make($p, ['patient_code' => 'nullable|string|max:40', 'legacy_code' => 'nullable|string|max:60', 'opening_date' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01|before_or_equal:'.$f['today'], 'paper_file_number' => 'nullable|string|max:60', 'import_note' => 'nullable|string|max:20000'])->validate();
        $matches = collect();
        $codes = array_values(array_filter([$p['patient_code'], $p['legacy_code']], fn ($v) => $v !== null));
        if ($codes) {
            $this->globalPermission($r, 'patients.search');
            $matches = collect($codes)->flatMap(fn ($code) => $this->identities[self::codeKey($code)] ?? [])->unique();
        }
        $this->require($matches->count() <= 1, 'patient_code', 'تعارض الكود النظامي والاسم البديل؛ يلزم مراجعة الهوية خارج الاستيراد.');
        $source = $this->sourcePatients[$p['source_record_id']] ?? null;
        if ($source) {
            $this->require($source->fingerprint === ImportBatches::fingerprint($p), 'source_record_id', 'معرّف المصدر محفوظ بمحتوى مختلف؛ لا يمكن استبداله.');
            $this->require($matches->isEmpty() || $matches->contains($source->patient_id), 'patient_code', 'هوية المصدر لا تطابق الكود.');
            $matches = collect([$source->patient_id]);
        }
        $patient = $matches->isNotEmpty() ? ($this->patients[$matches->first()] ?? null) : null;
        $this->require(! $p['patient_code'] || $patient !== null, 'patient_code', 'لم يُثبت تطابق هوية الكود؛ راجع الهوية بدل إنشاء مريض بالكود المصدر.');
        $this->require(! $patient || $patient->status === 'active', 'patient_code', 'الهوية غير متاحة للاستيراد؛ يلزم مراجعتها.');
        if ($patient && $p['patient_code']) {
            $this->require(in_array($patient->id, $this->identities[self::codeKey($p['patient_code'])] ?? [], true), 'patient_code', 'الكود لا يطابق الهوية المعينة.');
        }
        $dossier = $patient ? ($this->dossiers[$patient->id] ?? null) : null;
        $this->require(! $dossier || in_array($dossier->status, ['draft', 'active']), 'patient_code', 'السياق الطبي غير قابل للاستيراد.');
        $personal = $p;
        if ($p['birth_date_accuracy'] === 'year_only' && preg_match('/\A[1-9][0-9]{3}\z/', (string) $p['birth_date'])) {
            // Existing storage uses a date plus explicit precision. The precision remains year_only.
            $personal['birth_date'] = $p['birth_date'].'-01-01';
        }
        if ($patient) {
            $this->globalPermission($r, 'patients.search');
            foreach ([...SaveDossierSection::PERSON, 'paper_file_number'] as $key) {
                $this->require(($personal[$key] ?? null) === null || (string) $personal[$key] === (string) $patient->$key, $key, 'تختلف قيمة مصدر عن الهوية المحفوظة؛ لا يكتب الاستيراد فوقها. صحح الهوية خارج الدفعة.');
            }
            if ($dossier) {
                $this->require($dossier->opening_date === $p['opening_date'], 'opening_date', 'بداية الملف تختلف عن المحفوظ؛ يلزم مراجعتها دون استبدال تلقائي.');
                $this->require(! $p['legacy_code'] || $p['legacy_code'] === $dossier->code || $p['legacy_code'] === $patient->patient_code, 'legacy_code', 'إضافة اسم بديل إلى بطاقة موجودة تحتاج مراجعة خارج الاستيراد.');
            }
            $personal = ['person_mode' => 'existing', 'patient_id' => $patient->id, 'opening_date' => $p['opening_date']];
        } else {
            $this->globalPermission($r, 'patients.create');
            $this->require(! $p['paper_file_number'] || ! isset($this->paperFiles[self::codeKey($p['paper_file_number'])]), 'paper_file_number', 'رقم الملف الورقي مستخدم؛ راجع الهوية ولا تنشئ نسخة تلقائيًا.');
            $personal += ['person_mode' => 'new', 'code' => 'SYSTEM-GENERATED'];
        }
        if (! $dossier) {
            $this->facilityPermission($r, $f, 'create');
            $rules = $this->rules(SaveDossierSection::class, 'personal', $personal, false);
            unset($rules['visit_date']);
            Validator::make($personal + ['facility_id' => $f['id'], 'request_id' => (string) Str::uuid()], $rules, (new SaveDossierSection)->messages())->validate();
            if (! $patient) {
                $this->require(! $personal['birth_date'] || $personal['birth_date'] <= $f['today'], 'birth_date', 'الميلاد لا يكون في المستقبل.');
                $this->require($personal['birth_date'] || $personal['birth_date_accuracy'] === 'unknown', 'birth_date_accuracy', 'الميلاد الفارغ يحتاج دقة غير معروفة.');
                $this->require(! $personal['governorate_id'] || isset($this->governorates[$personal['governorate_id']]), 'governorate_id', 'المحافظة غير معتمدة.');
                $this->require(! $personal['city_id'] || ($this->cities[$personal['city_id']] ?? null) == $personal['governorate_id'], 'city_id', 'المدينة لا تتبع المحافظة.');
            }
        }
        $medicalKeys = ['is_oncology', 'disability_text', 'clinical_history', 'weight_kg', 'height_cm', 'previous_examinations', 'medication_source', 'other_organization'];
        $medical = array_filter(Arr::only($p, $medicalKeys), fn ($v) => $v !== null);
        if ($medical) {
            if ($dossier) {
                foreach ($medical as $key => $value) {
                    $same = in_array($key, ['weight_kg', 'height_cm'], true)
                        ? is_numeric($dossier->$key) && is_numeric($value) && round((float) $dossier->$key, 2) === round((float) $value, 2)
                        : (string) $dossier->$key === (string) $value;
                    $this->require($same, $key, 'تختلف المعلومات الطبية المحفوظة؛ يلزم مراجعتها خارج الاستيراد.');
                }
                $medical = [];
            } else {
                $this->facilityPermission($r, $f, 'medical.update');
                $this->validateSection(SaveDossierSection::class, 'medical', $medical, $f);
            }
        }
        $visits = [];
        foreach ($rows as $row) {
            if ($row['sheet'] !== 'Visits') {
                continue;
            }
            $this->facilityPermission($r, $f, 'visits.create');
            $v = $row['data'];
            $ref = $v['local_visit_ref'];
            $this->require(! isset($visits[$ref]), 'local_visit_ref', 'مرجع الزيارة مكرر.');
            $v['diagnoses'] = [];
            $clinical = ['services' => [], 'procedures' => []];
            $medications = ['prescription' => null, 'outcome' => null];
            $items = [];
            foreach ($rows as $child) {
                if (($child['data']['local_visit_ref'] ?? null) !== $ref || in_array($child['sheet'], ['Patients', 'Visits'])) {
                    continue;
                }
                $x = Arr::except($child['data'], ['source_record_id', 'local_visit_ref']);
                switch ($child['sheet']) {
                    case 'Diagnoses': $v['diagnoses'][] = $x;
                        break;
                    case 'Services': $clinical['services'][] = $x;
                        break;
                    case 'Procedures': $clinical['procedures'][] = $x;
                        break;
                    case 'Prescriptions':
                        $this->require($medications['prescription'] === null, 'prescription', 'تُقبل وصفة واحدة متعددة الأدوية لكل زيارة.');
                        $medications['prescription'] = $x + ['kind' => 'unlinked'];
                        break;
                    case 'Medications': $items[] = $x;
                        break;
                    case 'Outcomes':
                        $this->require($medications['outcome'] === null, 'outcome', 'تُقبل نتيجة صريحة واحدة لكل زيارة.');
                        $medications['outcome'] = $x;
                        break;
                }
            }
            $this->require(! $items || $medications['prescription'] !== null, 'prescription', 'أدوية الوصفة تحتاج رأس وصفة صريحًا.');
            if ($medications['prescription'] !== null) {
                $medications['prescription']['items'] = $items;
            }
            $this->validateSection(SaveDossierSection::class, 'visit', $v, $f, false);
            $this->require($v['visit_date'] >= $p['opening_date'] && $v['visit_date'] <= $f['today'], 'visit_date', 'تاريخ الزيارة يسبق بداية الملف أو يقع في المستقبل.');
            foreach ($v['diagnoses'] as $x) {
                $this->active('diagnoses', $x['diagnosis_id'], 'diagnosis_id');
                $this->context($f, $x['clinic_id'], $x['diagnosing_staff_id'], $v['visit_date']);
                $this->require(! $x['diagnosed_on'] || $x['diagnosed_on'] <= $f['today'], 'diagnosed_on', 'تاريخ التشخيص مستقبلي.');
            }
            if ($clinical['services'] || $clinical['procedures'] || $medications['prescription'] || $medications['outcome']) {
                $this->facilityPermission($r, $f, 'clinical.update');
                $this->validateSection(SaveVisitClinical::class, 'clinical', $clinical, $f);
                $this->validateSection(SaveVisitClinical::class, 'medications', $medications, $f);
            }
            foreach (['services', 'procedures'] as $kind) {
                foreach ($clinical[$kind] as $x) {
                    $this->active($kind, $x['catalog_id'], 'catalog_id', true);
                    $this->context($f, $x['clinic_id'], $x['doctor_id'], $v['visit_date']);
                }
            }
            if ($rx = $medications['prescription']) {
                $this->require(count($items) > 0, 'prescription', 'الوصفة تحتاج دواء واحدًا على الأقل؛ لا تُنشأ وصفة من دواء غير موثق.');
                $this->context($f, $rx['prescribing_clinic_id'], $rx['prescribing_staff_id'], $v['visit_date']);
                $this->require($rx['prescribed_on'] >= $v['visit_date'] && $rx['prescribed_on'] <= $f['today'], 'prescribed_on', 'تاريخ الوصفة خارج مجال الزيارة.');
                foreach ($items as $x) {
                    $this->active('medications', $x['medication_id'], 'medication_id');
                }
            }
            if ($o = $medications['outcome']) {
                $this->require(DB::table('visit_results')->where('code', $o['code'])->where('is_active', true)->exists(), 'code', 'تعريف المآل غير فعال أو غير موجود.');
                $this->context($f, $o['clinic_id'], $o['doctor_id'], $v['visit_date']);
                $this->require($o['outcome_on'] >= $v['visit_date'] && $o['outcome_on'] <= $f['today'], 'outcome_on', 'تاريخ المآل خارج مجال الزيارة.');
                if ($o['code'] === 'DOS-REFER') {
                    $this->require($o['outgoing_referral_date'] >= $v['visit_date'] && $o['outgoing_referral_date'] <= $o['outcome_on'], 'outgoing_referral_date', 'تاريخ الإحالة خارج مجال المآل.');
                }
            }
            $visits[$ref] = ['visit' => $v, 'clinical' => $clinical, 'medications' => $medications];
        }

        return ['patient' => $patient ? (array) $patient : null, 'dossier' => $dossier ? (array) $dossier : null, 'personal' => $personal, 'source_patient' => $p, 'medical' => $medical, 'visits' => $visits];
    }

    public function write(Request $r, array $f, array $bundle): array
    {
        $dossier = $bundle['dossier']['id'] ?? null;
        if (! $dossier) {
            $p = $bundle['personal'];
            if ($p['person_mode'] === 'new') {
                $p['code'] = app(PatientCardCodes::class)->next();
            }
            $dossier = app(DossierPersonalWriter::class)->save($r, $f, $p + ['request_id' => (string) Str::uuid()], null, withoutVisit: true);
            $patientId = DB::table('patient_dossiers')->where('id', $dossier)->value('patient_id');
            if ($p['person_mode'] === 'new' && $bundle['source_patient']['paper_file_number']) {
                $patient = DB::table('patients')->where('id', $patientId)->lockForUpdate()->first();
                $this->require($patient->paper_file_number === null, 'paper_file_number', 'لا يستبدل الاستيراد رقم الملف الورقي المحفوظ.');
                $before = ['paper_file_number' => $patient->paper_file_number];
                $after = ['paper_file_number' => $bundle['source_patient']['paper_file_number']];
                DB::table('patients')->where('id', $patientId)->update($after);
                app(DossierWrites::class)->audit($r, $f, 'patient', $patientId, $before, $after);
            }
            if ($alias = $bundle['source_patient']['legacy_code']) {
                $this->require(! DB::table('patient_dossiers')->where('code', $alias)->where('patient_id', '<>', $patientId)->exists() && ! DB::table('patients')->where('patient_code', $alias)->where('id', '<>', $patientId)->exists(), 'legacy_code', 'الاسم البديل محجوز لهوية أخرى.');
                $context = DB::table('patient_dossiers')->where('id', $dossier)->where('facility_id', $f['id'])->lockForUpdate()->first();
                if ($context->code !== $alias) {
                    $before = ['code' => $context->code];
                    $after = ['code' => $alias];
                    DB::table('patient_dossiers')->where('id', $dossier)->where('facility_id', $f['id'])->update($after);
                    app(DossierWrites::class)->audit($r, $f, 'patient_dossier', $dossier, $before, $after);
                }
            }
        }
        if ($bundle['medical']) {
            app(DossierMedicalWriter::class)->save($r, $f, $dossier, $bundle['medical'] + ['request_id' => (string) Str::uuid(), 'lock_version' => DB::table('patient_dossiers')->where('id', $dossier)->value('lock_version')]);
        }
        $visits = [];
        foreach ($bundle['visits'] as $ref => $data) {
            $hasVisit = DB::table('visits')->where('dossier_id', $dossier)->exists();
            $id = app(DossierVisitWriter::class)->save($r, $f, $dossier, $data['visit'] + ['request_id' => (string) Str::uuid()], null, subsequent: $hasVisit, import: true);
            if (! $hasVisit) {
                DB::table('patient_dossiers')->where('id', $dossier)->whereNull('registration_visit_id')->update(['registration_visit_id' => $id]);
            }
            foreach (['clinical', 'medications'] as $section) {
                if (array_filter($data[$section])) {
                    $version = DB::table('visits')->where('id', $id)->value('lock_version');
                    app(DossierClinicalWriter::class)->save($r, $f, $dossier, $id, $section, $data[$section] + ['request_id' => (string) Str::uuid(), 'lock_version' => $version]);
                }
            }
            $visits[$ref] = $id;
        }

        return ['dossier_id' => (int) $dossier, 'visits' => $visits];
    }

    public function rememberCommitted(array $bundle): void
    {
        if ($paper = $bundle['source_patient']['paper_file_number']) {
            $this->paperFiles[self::codeKey($paper)] = true;
        }
    }

    private function rules(string $class, string $section, array $input, bool $put): array
    {
        $request = $class::create('/', $put ? 'PUT' : 'POST', $input);
        $route = new Route($put ? 'PUT' : 'POST', '/', fn () => null);
        $route->defaults('section', $section)->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request->rules();
    }

    private function validateSection(string $class, string $section, array $input, array $f, bool $put = true): void
    {
        $input += ['facility_id' => $f['id'], 'request_id' => (string) Str::uuid()];
        if ($put) {
            $input['lock_version'] = 1;
        }
        Validator::make($input, $this->rules($class, $section, $input, $put), (new SaveDossierSection)->messages())->validate();
    }

    private function active(string $table, int $id, string $field, bool $archived = false): void
    {
        if (! isset($this->references[$table])) {
            $ids = $this->referenceIds[$table] ?? [$id];
            $q = DB::table($table)->whereIn('id', $ids)->where('is_active', true);
            if ($archived) {
                $q->whereNull('archived_at');
            }
            $this->references[$table] = array_fill_keys($q->pluck('id')->all(), true);
        }
        $this->require(isset($this->references[$table][$id]), $field, 'مرجع الدليل غير معروف أو غير فعال. لا ينشئ الاستيراد عناصر دليل.');
    }

    private function context(array $f, int $clinic, int $doctor, string $date): void
    {
        $key = implode(':', [$clinic, $doctor, $date]);
        if (! isset($this->checkedContexts[$key])) {
            app(DossierClinicalContext::class)->check($f, $clinic, $doctor, $date, 'doctor_id', false);
            $this->checkedContexts[$key] = true;
        }
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
