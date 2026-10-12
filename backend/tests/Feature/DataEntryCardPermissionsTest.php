<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dossiers\OncologyQueries;
use App\Services\Users\DataEntryCardPermissions;
use Database\Seeders\PermissionMatrixPhaseOneSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DossierCompletionFixture;
use Tests\TestCase;

class DataEntryCardPermissionsTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    private User $clerk;

    private int $role;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = DossierCompletionFixture::make();
        // Exercise creation of the default role without deleting any existing
        // role, assignment or schema. This entire fixture rolls back.
        DB::table('roles')->where('code', 'data_entry')->update(['code' => 'previous-data-entry-'.$this->f['tag']]);
        $this->seed(PermissionMatrixPhaseOneSeeder::class);
        $this->role = DB::table('roles')->where('code', 'data_entry')->value('id');
        $this->clerk = User::factory()->create();
        DB::table('facility_user_roles')->insert(['user_id' => $this->clerk->id, 'facility_id' => $this->f['facility'], 'role_id' => $this->role]);
        $this->token = $this->clerk->createToken('data-entry-card', ['api'])->plainTextToken;
    }

    private function api(string $method, string $path, array $body = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/'.$path, $body + ['facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid()], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function register(): array
    {
        $body = ['request_id' => (string) Str::uuid(), 'person_mode' => 'new', 'first_name' => 'أحمد', 'family_name' => 'بطاقة إدخال '.$this->f['tag'], 'phone' => '0900000000', 'opening_date' => '2001-03-01', 'visit_date' => '2001-03-02', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'];
        $card = $this->api('POST', 'patient-cards/registrations', $body)->assertCreated()->json('data');
        $this->api('POST', 'patient-cards/registrations', $body)->assertCreated()->assertJsonPath('data.id', $card['id']);
        $this->assertSame(1, DB::table('visits')->where('dossier_id', $card['id'])->count());

        return $this->api('GET', 'dossiers/'.$card['id'].'/visits/'.$card['registration_visit_id'].'/progress')->assertOk()->json('data');
    }

    private function context(): array
    {
        return ['clinic_id' => $this->f['clinics'][0], 'doctor_id' => $this->f['workflow_doctors'][0]];
    }

    public function test_data_entry_saves_three_funded_invoices_and_funding_does_not_require_treatment_permission(): void
    {
        $this->artisan('dossiers:setup-prescription-funding', ['--apply' => true, '--facility' => $this->f['facility'], '--execution-reference' => 'LOCAL approved funding test'])->assertSuccessful();
        DB::table('role_permissions')->where('role_id', $this->role)->where('permission_id', DB::table('permissions')->where('code', 'dossiers.treatment.view')->value('id'))->delete();
        $sources = $this->api('GET', 'dossiers/prescription-funding')->assertOk()->json('data.funding_sources');
        $approved = array_values(array_filter($sources, fn ($s) => in_array($s['code'], ['ministry_of_health', 'al_rowad', 'personal_expense'], true)));
        $this->assertCount(3, $approved);
        $s = $this->register();
        $path = 'dossiers/'.$s['id'].'/visits/'.$s['visit']['id'];
        foreach ($approved as $i => $source) {
            $input = ['request_id' => (string) Str::uuid(), 'lock_version' => $s['visit']['lock_version'], 'outcome' => null, 'prescription' => ['kind' => 'dose_linked', 'prescribing_clinic_id' => $this->f['clinics'][0], 'prescribing_staff_id' => $this->f['workflow_doctors'][0], 'prescribed_on' => '2001-03-0'.($i + 1), 'funding_source_id' => $source['id'], 'note' => "Invoice $i", 'items' => [['medication_id' => $this->f['medication'], 'display_order' => 0, 'note' => "Item $i"]]]];
            $s = $this->api('PUT', $path.'/medications', $input)->assertOk()->json('data');
            $this->api('PUT', $path.'/medications', $input)->assertOk();
        }
        $before = $s['clinical']['prescriptions'];
        $changed = $before[1];
        $changed['note'] = 'Only second invoice corrected';
        $s = $this->api('PUT', $path.'/medications', ['lock_version' => $s['visit']['lock_version'], 'prescription' => $changed, 'outcome' => null])->assertOk()->json('data');
        $this->assertSame($before[0], $s['clinical']['prescriptions'][0]);
        $this->assertSame($before[2], $s['clinical']['prescriptions'][2]);
        $this->api('GET', $path.'/progress')->assertOk()->assertJsonCount(3, 'data.clinical.prescriptions')->assertJsonPath('data.clinical.prescriptions.1.note', 'Only second invoice corrected');
        $this->assertSame(array_column($approved, 'id'), array_column($s['clinical']['prescriptions'], 'funding_source_id'));
        $this->assertSame(0, DB::table('visit_medications')->where('visit_id', $s['visit']['id'])->count());
        $this->api('GET', 'dossiers/prescription-funding', ['facility_id' => $this->f['other']])->assertForbidden();
        DB::table('role_permissions')->where('role_id', $this->role)->where('permission_id', DB::table('permissions')->where('code', 'dossiers.prescriptions.update')->value('id'))->delete();
        $this->api('GET', 'dossiers/prescription-funding')->assertForbidden();
    }

    public function test_funding_setup_is_previewed_repeatable_preserves_disabled_sources_and_rejects_ambiguity(): void
    {
        $codes = array_keys(OncologyQueries::MEDICATION_SOURCES);
        $names = array_values(OncologyQueries::MEDICATION_SOURCES);
        // Preserve any existing referenced test definitions and their IDs; isolate
        // the approved names/codes temporarily within this rolled-back fixture.
        foreach (DB::table('funding_sources')->whereIn('code', $codes)->orWhereIn('name_ar', $names)->get() as $row) {
            DB::table('funding_sources')->where('id', $row->id)->update(['code' => 'OLD-'.$row->id.'-'.$this->f['tag'], 'name_ar' => 'Old '.$row->id]);
        }
        $before = DB::table('funding_sources')->orderBy('id')->get()->toJson();
        $this->artisan('dossiers:setup-prescription-funding')->assertSuccessful();
        $this->assertSame($before, DB::table('funding_sources')->orderBy('id')->get()->toJson());
        $args = ['--apply' => true, '--facility' => $this->f['facility'], '--execution-reference' => 'LOCAL operator funding verification'];
        $this->artisan('dossiers:setup-prescription-funding', $args)->assertSuccessful();
        $this->assertSame(5, DB::table('funding_sources')->whereIn('code', $codes)->count());
        DB::table('funding_sources')->where('code', 'none')->update(['is_active' => false]);
        DB::table('funding_sources')->where('code', 'ministry_of_health')->update(['code' => 'HISTORICAL-MOH-'.$this->f['tag']]);
        $after = DB::table('funding_sources')->orderBy('id')->get()->toJson();
        $audit = DB::table('audit_logs')->count();
        $this->artisan('dossiers:setup-prescription-funding', $args)->assertSuccessful();
        $this->assertSame($after, DB::table('funding_sources')->orderBy('id')->get()->toJson());
        $this->assertSame($audit, DB::table('audit_logs')->count());
        $visible = $this->api('GET', 'dossiers/prescription-funding')->assertOk()->json('data.funding_sources');
        $this->assertNotContains('none', array_column($visible, 'code'));
        DB::table('funding_sources')->update(['is_active' => false]);
        $this->api('GET', 'dossiers/prescription-funding')->assertOk()->assertJsonPath('data.funding_sources', []);
        DB::table('funding_sources')->insert(['code' => 'AMBIGUOUS-'.$this->f['tag'], 'name_ar' => $names[0], 'is_active' => true]);
        $before = DB::table('funding_sources')->orderBy('id')->get()->toJson();
        $this->artisan('dossiers:setup-prescription-funding', $args)->assertFailed();
        $this->assertSame($before, DB::table('funding_sources')->orderBy('id')->get()->toJson());
    }

    public function test_default_data_entry_saves_all_card_sections_and_completes_a_real_visit_without_global_roles(): void
    {
        $this->assertSame(1, DB::table('facility_user_roles')->where('user_id', $this->clerk->id)->count());
        $this->assertSame(0, DB::table('global_user_roles')->where('user_id', $this->clerk->id)->count());
        $s = $this->register();
        $base = 'dossiers/'.$s['id'];
        $path = $base.'/visits/'.$s['visit']['id'];
        $options = $this->api('GET', 'dossiers/options')->assertOk()->json('data.capabilities');
        foreach (['medical_update', 'visits_create', 'visits_update', 'diagnoses_update', 'services_update', 'procedures_update', 'prescriptions_update', 'outcomes_update', 'attachments_view', 'attachments_upload', 'attachments_download', 'assessment_update', 'pathology_create', 'pathology_update', 'treatment_view', 'treatment_create', 'treatment_update', 'treatment_schedule_create', 'treatment_schedule_update', 'treatment_administer', 'treatment_dispense', 'finalize', 'visits_complete'] as $cap) {
            $this->assertTrue($options[$cap], $cap);
        }
        foreach (['diagnoses_create', 'medications_create', 'delete', 'export', 'audit', 'patients_update', 'treatment_override', 'attachments_void'] as $cap) {
            $this->assertFalse($options[$cap], $cap);
        }
        foreach (['clinics', 'diagnoses', 'services', 'procedures', 'medications', 'outcomes'] as $lookup) {
            $this->api('GET', 'dossiers/options/'.$lookup)->assertOk();
        }
        $this->api('GET', 'dossiers/options/doctors', ['clinic_id' => $this->f['clinics'][0], 'visit_date' => '2001-03-02'])->assertOk()->assertJsonFragment(['id' => $this->f['workflow_doctors'][0]]);
        $s = $this->api('PUT', $base.'/medical', ['lock_version' => $s['lock_version'], 'is_oncology' => true, 'clinical_history' => 'قصة محفوظة', 'history' => ['medical'], 'treatment' => [], 'weight_kg' => '65.50', 'height_cm' => '170.00'])->assertOk()->json('data');
        $s = $this->api('PUT', $path, ['lock_version' => $s['visit']['lock_version'], 'visit_date' => '2001-03-02', 'is_referred' => false, 'diagnoses' => [['diagnosis_id' => $this->f['diagnosis'], 'clinic_id' => $this->f['clinics'][0], 'diagnosing_staff_id' => $this->f['workflow_doctors'][0]]]])->assertOk()->json('data');
        $s = $this->api('PUT', $path.'/clinical', ['lock_version' => $s['visit']['lock_version'], 'services' => [['catalog_id' => $this->f['service']] + $this->context()], 'procedures' => [['catalog_id' => $this->f['procedure']] + $this->context()]])->assertOk()->json('data');
        $funding = DB::table('funding_sources')->insertGetId(['code' => 'ENTRY-'.$this->f['tag'], 'name_ar' => 'مصدر اختبار', 'is_active' => true]);
        foreach (['unlinked', 'dose_linked', 'outside'] as $kind) {
            $extra = $kind === 'dose_linked' ? ['funding_source_id' => $funding] : ($kind === 'outside' ? ['unavailable_reason' => 'الدواء غير متوفر في المشفى'] : []);
            $outcome = ['code' => 'DOS-NORX', 'outcome_on' => '2001-03-02'] + $this->context();
            if ($s['clinical']['outcome']) {
                $outcome += ['id' => $s['clinical']['outcome']['id'], 'lock_version' => $s['clinical']['outcome']['lock_version']];
            }
            $s = $this->api('PUT', $path.'/medications', ['lock_version' => $s['visit']['lock_version'], 'prescription' => ['kind' => $kind, 'prescribing_clinic_id' => $this->f['clinics'][0], 'prescribing_staff_id' => $this->f['workflow_doctors'][0], 'prescribed_on' => '2001-03-02', 'items' => [['medication_id' => $this->f['medication'], 'display_order' => 0, 'note' => 'تعليمات محفوظة']]] + $extra, 'outcome' => $outcome])->assertOk()->json('data');
        }
        $this->assertSame(3, DB::table('visit_prescriptions')->where('visit_id', $s['visit']['id'])->whereNull('voided_at')->count());
        $report = $this->api('POST', $path.'/pathology', ['source' => 'external', 'external_organization' => 'مختبر اصطناعي', 'status' => 'completed', 'result_on' => '2001-03-01', 'conclusion' => 'تقرير مسجل'] + $this->context())->assertCreated()->json('data.id');
        $this->api('PUT', $path.'/pathology/'.$report, ['source' => 'external', 'external_organization' => 'synthetic lab', 'status' => 'completed', 'clinic_id' => $this->f['clinics'][0], 'doctor_id' => $this->f['workflow_doctors'][0], 'result_on' => '2001-03-01', 'lock_version' => 1, 'conclusion' => 'تقرير مستكمل'])->assertOk();
        $this->api('PUT', $path.'/diagnostic-assessment', ['lock_version' => 0, 'disposition' => 'pathology_confirmed', 'evidence_pathology_id' => $report, 'assessed_on' => '2001-03-02'])->assertOk();
        $plan = $this->api('POST', $base.'/treatment-plans', ['modality' => 'chemotherapy', 'intent' => 'curative', 'protocol_text' => 'بروتوكول مسجل', 'protocol_clinic_id' => $this->f['clinics'][0], 'protocol_doctor_id' => $this->f['workflow_doctors'][0], 'treating_clinic_id' => $this->f['clinics'][0], 'treating_doctor_id' => $this->f['workflow_doctors'][0]])->assertCreated()->json('data');
        $this->api('POST', $base.'/treatment-plans/'.$plan['id'].'/sessions', ['lock_version' => $plan['lock_version'], 'sessions' => [['planned_on' => '2001-03-02']]])->assertCreated();
        $session = $this->api('GET', $base.'/treatment-sessions')->assertOk()->json('data.0');
        $this->api('POST', $base.'/treatment-sessions/'.$session['id'].'/session-doses', ['given_on' => '2001-03-02', 'dose_name' => 'جرعة علاجية', 'complaint' => 'مسجل', 'recommendations' => 'مسجلة', 'nurse_id' => $this->f['workflow_doctors'][0], 'medication_source' => 'ministry_of_health'])->assertCreated();
        $this->api('POST', $base.'/treatment-sessions', ['planned_on' => '2001-03-02'] + $this->context())->assertCreated();
        $plan = $this->api('GET', $base.'/treatment-plans/'.$plan['id'])->assertOk()->json('data');
        $med = ['medication_id' => $this->f['medication'], 'medication_source' => 'ministry_of_health', 'quantity' => '1.5', 'quantity_unit' => 'vial'];
        $this->api('POST', $path.'/doses', ['session_id' => $session['id'], 'session_lock_version' => $session['lock_version'], 'plan_lock_version' => $plan['lock_version'], 'visit_lock_version' => $s['visit']['lock_version'], 'administered_on' => '2001-03-02', 'supervising_staff_id' => $this->f['workflow_doctors'][0], 'administered_by' => $this->f['workflow_doctors'][0], 'items' => [['dose_value' => '2.5', 'dose_unit' => 'mg', 'route' => 'IV'] + $med]])->assertCreated();
        $this->api('POST', $path.'/dispensing', ['dispensed_on' => '2001-03-02', 'prescribing_clinic_id' => $this->f['clinics'][0], 'prescribing_staff_id' => $this->f['workflow_doctors'][0], 'dispensing_purpose' => 'take_home'] + $med)->assertCreated();
        Storage::persistentFake('local');
        $upload = $this->api('POST', $path.'/uploads', ['lock_version' => $s['visit']['lock_version'], 'title' => 'صورة محفوظة', 'original_filename' => 'synthetic.png'])->assertCreated()->json('data.upload_id');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV4sAAAAASUVORK5CYII=');
        $this->app['auth']->forgetGuards();
        $attachment = $this->withToken($this->token)->post('/api/'.$path.'/uploads/'.$upload, ['facility_id' => $this->f['facility'], 'file' => UploadedFile::fake()->createWithContent('synthetic.png', $png)], ['Accept' => 'application/json'])->assertCreated()->json('data.attachment_id');
        $this->api('GET', $base.'/attachments')->assertOk()->assertJsonFragment(['id' => $attachment]);
        $this->api('GET', $path.'/attachments/'.$attachment.'/download')->assertOk();
        $s = $this->api('GET', $path.'/progress')->assertOk()->json('data');
        $s = $this->api('POST', $path.'/review', ['lock_version' => $s['visit']['lock_version'], 'confirmed' => true])->assertOk()->json('data');
        $s = $this->api('POST', $path.'/complete', ['lock_version' => $s['visit']['lock_version'], 'dossier_lock_version' => $s['lock_version'], 'confirmed' => true, 'clinic_id' => $this->f['clinics'][0], 'attending_staff_id' => $this->f['workflow_doctors'][0]])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.visit.status', 'complete')->json('data');
        $this->api('PUT', $path.'/clinical', ['lock_version' => $s['visit']['lock_version'], 'services' => [], 'procedures' => []])->assertConflict();
        $this->api('DELETE', $base)->assertForbidden();
        $this->api('POST', $base.'/report/xlsx')->assertForbidden();
        $this->api('POST', $base.'/visits/subsequent', ['visit_date' => '2001-03-03', 'is_referred' => false, 'diagnoses' => [['diagnosis_id' => $this->f['diagnosis'], 'clinic_id' => $this->f['clinics'][0], 'diagnosing_staff_id' => $this->f['workflow_doctors'][0]]]])->assertCreated()->assertJsonPath('data.visit.status', 'draft');
        $this->assertSame(2, DB::table('visits')->where('dossier_id', $s['id'])->count());
        $this->api('GET', $path.'/progress', ['facility_id' => $this->f['other']])->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'visit_services', 'actor_id' => $this->clerk->id]);
    }

    private function creationRequests(): array
    {
        $category = DB::table('service_categories')->where('is_active', true)->value('id');
        $type = DB::table('staff')->where('id', $this->f['workflow_doctors'][0])->value('staff_type_id');

        return [
            ['clinics', 'clinics', ['name_ar' => 'عيادة جديدة '.$this->f['tag'], 'is_active' => true]],
            ['doctors', 'staff', ['name' => 'طبيب جديد '.$this->f['tag'], 'staff_type_id' => $type, 'is_active' => true, 'specialty_ids' => []]],
            ['service-catalog', 'services', ['kind' => 'service', 'name_ar' => 'خدمة جديدة '.$this->f['tag'], 'category_id' => $category, 'is_active' => true]],
            ['service-catalog', 'procedures', ['kind' => 'procedure', 'name_ar' => 'إجراء جديد '.$this->f['tag'], 'is_active' => true]],
            ['service-catalog', 'medications', ['kind' => 'medication', 'name_ar' => 'دواء دليل '.$this->f['tag'], 'is_active' => true]],
            ['service-catalog/categories', 'service_categories', ['name_ar' => 'فئة جديدة '.$this->f['tag'], 'is_active' => true]],
            ['dossiers/diagnoses', 'diagnoses', ['name_ar' => 'تشخيص جديد '.$this->f['tag']]],
            ['dossiers/medications', 'medications', ['name_ar' => 'دواء سريع '.$this->f['tag']]],
        ];
    }

    public function test_directory_creation_is_denied_without_writes_and_remains_available_to_explicitly_authorized_users(): void
    {
        foreach ($this->creationRequests() as [$path, $table, $body]) {
            $before = DB::table($table)->count();
            $this->api('POST', $path, $body)->assertForbidden();
            $this->assertSame($before, DB::table($table)->count(), $path);
        }
        $this->api('GET', 'clinics')->assertOk();
        $access = $this->api('GET', 'user')->assertOk()->json('data.access');
        $permissions = collect($access)->firstWhere('facility.id', $this->f['facility'])['permissions'];
        $this->assertNotContains('clinics.create', $permissions);
        $this->api('GET', 'doctors/options')->assertOk()->assertJsonPath('data.capabilities.create', false);
        $this->api('GET', 'service-catalog/options')->assertOk();
        foreach (['service', 'procedure', 'medication'] as $kind) {
            $this->api('GET', 'service-catalog', ['kind' => $kind])->assertOk()->assertJsonPath('capabilities.create', false);
        }
        $user = User::factory()->create();
        $role = DB::table('roles')->insertGetId(['code' => 'directory-author-'.$this->f['tag'], 'name_ar' => 'مخول إضافة']);
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'facility_id' => $this->f['facility'], 'role_id' => $role]);
        DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
        foreach (DB::table('permissions')->whereIn('code', [...DataEntryCardPermissions::GRANTS, ...DataEntryCardPermissions::DIRECTORY_CREATION])->pluck('id') as $id) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
        $token = $user->createToken('directory-author', ['api'])->plainTextToken;
        foreach ($this->creationRequests() as [$path, $table, $body]) {
            $before = DB::table($table)->count();
            $this->api('POST', $path, $body, $token)->assertCreated();
            $this->assertSame($before + 1, DB::table($table)->count(), $path);
        }
    }

    public function test_operator_correction_is_dry_run_by_default_scoped_audited_repeatable_and_preserves_other_roles(): void
    {
        DB::table('role_permissions')->where('role_id', $this->role)->where('permission_id', DB::table('permissions')->where('code', 'dossiers.attachments.download')->value('id'))->delete();
        foreach (DB::table('permissions')->whereIn('code', DataEntryCardPermissions::DIRECTORY_CREATION)->pluck('id') as $id) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->role, 'permission_id' => $id]);
        }
        $before = DB::table('role_permissions')->where('role_id', '!=', $this->role)->orderBy('id')->get()->toJson();
        $assignments = DB::table('facility_user_roles')->orderBy('id')->get()->toJson();
        $global = DB::table('global_user_roles')->orderBy('id')->get()->toJson();
        $version = DB::table('roles')->where('id', $this->role)->value('lock_version');
        $audits = DB::table('audit_logs')->count();
        $this->artisan('access:data-entry-card-permissions')->assertSuccessful();
        $this->assertSame($version, DB::table('roles')->where('id', $this->role)->value('lock_version'));
        $this->assertSame($audits, DB::table('audit_logs')->count());
        $args = ['--apply' => true, '--facility' => (string) $this->f['facility'], '--execution-reference' => 'TEST/data-entry'];
        $this->artisan('access:data-entry-card-permissions', $args)->assertSuccessful();
        $this->assertSame($before, DB::table('role_permissions')->where('role_id', '!=', $this->role)->orderBy('id')->get()->toJson());
        $this->assertSame($assignments, DB::table('facility_user_roles')->orderBy('id')->get()->toJson());
        $this->assertSame($global, DB::table('global_user_roles')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $this->role)->whereIn('p.code', DataEntryCardPermissions::DIRECTORY_CREATION)->count());
        $audit = DB::table('audit_logs')->where('entity_type', 'role')->where('entity_id', $this->role)->where('reason', 'TEST/data-entry')->first();
        $this->assertNull($audit->actor_id);
        $this->assertSame('TEST/data-entry', json_decode($audit->new_values, true)['execution_reference']);
        $this->artisan('access:data-entry-card-permissions', $args)->assertSuccessful();
        $this->assertSame($audits + 1, DB::table('audit_logs')->count());
        $this->assertSame($version + 1, DB::table('roles')->where('id', $this->role)->value('lock_version'));
        $id = DB::table('permissions')->where('code', 'dossiers.medical.update')->value('id');
        DB::table('role_permissions')->where('role_id', $this->role)->where('permission_id', $id)->delete();
        $this->seed(PermissionMatrixPhaseOneSeeder::class);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $this->role, 'permission_id' => $id]);
    }

    public function test_disabled_required_definition_or_protected_target_blocks_the_entire_apply(): void
    {
        $before = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        DB::table('permissions')->where('code', 'dossiers.medical.update')->update(['is_active' => false]);
        $args = ['--apply' => true, '--facility' => (string) $this->f['facility'], '--execution-reference' => 'TEST/blocked'];
        $this->artisan('access:data-entry-card-permissions', $args)->assertFailed();
        $this->assertSame($before, DB::table('role_permissions')->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('permissions', ['code' => 'dossiers.medical.update', 'is_active' => false]);
        DB::table('permissions')->where('code', 'dossiers.medical.update')->update(['is_active' => true]);
        DB::table('roles')->where('id', $this->role)->update(['is_system_super_admin' => true]);
        $this->artisan('access:data-entry-card-permissions', $args)->assertFailed();
        $this->assertSame($before, DB::table('role_permissions')->orderBy('id')->get()->toJson());
    }
}
