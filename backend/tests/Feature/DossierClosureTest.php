<?php

namespace Tests\Feature;

use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierAuditHistory;
use App\Services\Dossiers\DossierReports;
use Carbon\CarbonImmutable;
use Database\Seeders\DossierAuditPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DossierCompletionCase;

class DossierClosureTest extends DossierCompletionCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new DossierAuditPermissionsSeeder)->run();
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', 'dossiers.audit')->value('id')]);
    }

    private function history(array $filters = [])
    {
        return $this->callApi('GET', "/{$this->s['id']}/audit", $filters);
    }

    private function event(array $overrides = []): int
    {
        return DB::table('audit_logs')->insertGetId($overrides + ['facility_id' => $this->f['facility'], 'actor_id' => $this->f['user']->id, 'entity_type' => 'dossier_visit', 'entity_id' => $this->s['visit']['id'], 'event' => 'saved', 'old_values' => json_encode(['visit_date' => '2001-03-01']), 'new_values' => json_encode(['visit_date' => '2001-03-02']), 'request_id' => (string) Str::uuid(), 'occurred_at' => '2020-02-02 12:00:00']);
    }

    public function test_history_requires_both_permissions_and_enforces_dossier_facility_and_visit_scope(): void
    {
        $id = $this->event();
        $this->event(['facility_id' => $this->f['other']]);
        $this->event(['entity_id' => 99999999]);
        $this->event(['entity_type' => 'patient_dossier', 'entity_id' => $this->f['dossiers'][0]]);
        $response = $this->history(['from' => '2020-02-02', 'to' => '2020-02-02'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->history(['facility_id' => $this->f['other']])->assertForbidden();
        $this->history(['visit_id' => 99999999])->assertNotFound();
        foreach (['dossiers.audit', 'dossiers.view'] as $code) {
            $permission = DB::table('permissions')->where('code', $code)->value('id');
            DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
            $denied = $this->history()->assertForbidden()->assertJsonPath('error.code', 'DOSSIER_ACCESS_DENIED')->json();
            $this->assertArrayNotHasKey('data', $denied);
            $this->assertArrayNotHasKey('meta', $denied);
            DB::table('role_permissions')->insert(['role_id' => $this->f['dossier_role'], 'permission_id' => $permission]);
        }
    }

    public function test_safe_readable_changes_omission_and_every_filter_with_stable_pagination(): void
    {
        $ids = [];
        for ($i = 0; $i < 23; $i++) {
            $ids[] = $this->event();
        }
        $unsafe = ['password' => 'SECRET-PASSWORD', 'storage_key' => 'PRIVATE-KEY', 'request' => ['token' => 'SECRET-TOKEN'], 'path' => '/private/secret'];
        $safe = $this->event(['entity_type' => 'patient', 'entity_id' => $this->s['patient']['id'], 'old_values' => json_encode(['first_name' => 'أحمد', 'family_name' => 'محمد']), 'new_values' => json_encode(['first_name' => 'محمود'] + $unsafe)]);
        $this->event(['event' => 'saved', 'new_values' => json_encode(['voided_at' => '2020-02-02 12:00:00', 'void_reason' => 'تصحيح الإدخال'])]);
        $filters = ['from' => '2020-02-02', 'to' => '2020-02-02', 'entity' => 'dossier_visit', 'action' => 'updated', 'visit_id' => $this->s['visit']['id'], 'per_page' => 10];
        $all = [];
        foreach ([1, 2, 3] as $page) {
            $result = $this->history($filters + ['page' => $page])->assertOk()->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 3)->json('data');
            $all = [...$all, ...array_column($result, 'id')];
        }
        $this->assertSame(array_reverse($ids), $all);
        $record = $this->history(['entity' => 'patient', 'from' => '2020-02-02', 'to' => '2020-02-02'])->assertOk()->assertJsonPath('data.0.id', $safe)->json('data.0');
        $this->assertSame([['field' => 'first_name', 'label' => 'الاسم الأول', 'before' => 'أحمد', 'after' => 'محمود', 'before_recorded' => true]], $record['changes']);
        $text = json_encode($record);
        foreach (['SECRET', 'PRIVATE', '/private', 'password', 'storage_key', 'family_name'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $text);
        }
        $this->history(['action' => 'voided'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reason', 'تصحيح الإدخال');
        $this->history(['to' => '2019-01-01'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->history(['from' => '2020-02-03', 'to' => '2020-02-02'])->assertUnprocessable();
        $this->history(['entity' => 'unknown'])->assertUnprocessable();
        $this->history(['action' => 'unknown'])->assertUnprocessable();
    }

    public function test_actual_prescription_audits_resolve_snapshots_without_replay_duplicates_or_read_writes(): void
    {
        $request = ['prescription' => $this->rx(), 'outcome' => $this->outcome(['code' => 'DOS-RX']), 'request_id' => (string) Str::uuid()];
        $this->saveSection('medications', $request)->assertOk();
        $before = DB::table('audit_logs')->count();
        $this->saveSection('medications', $request)->assertOk();
        $this->assertSame($before, DB::table('audit_logs')->count());
        $records = $this->history(['entity' => 'visit_prescription_items'])->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertSame(['created'], array_values(array_unique(array_column($records, 'action'))));
        $fields = array_column($records[0]['changes'], 'field');
        $this->assertContains('medication_code_snapshot', $fields);
        $this->assertContains('medication_name_snapshot', $fields);
        $this->history(['entity' => 'visit_prescriptions'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_attachment_history_and_report_metadata_require_their_own_permission_even_when_voided(): void
    {
        Storage::fake('dossier_private');
        $ticket = $this->callApi('POST', $this->path('/uploads'), ['lock_version' => $this->s['visit']['lock_version'], 'title' => 'مرفق اصطناعي', 'original_filename' => 'scan.png'])->assertCreated()->json('data.upload_id');
        $file = UploadedFile::fake()->image('scan.png', 10, 10);
        $url = '/api/dossiers'.$this->path('/uploads/'.$ticket).'?facility_id='.$this->f['facility'];
        $id = $this->post($url, ['file' => $file], ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])->assertCreated()->json('data.attachment_id');
        $s = $this->callApi('GET', $this->path('/progress'))->assertOk()->json('data');
        $this->callApi('POST', $this->path('/attachments/'.$id.'/void'), ['lock_version' => $s['visit']['lock_version'], 'attachment_lock_version' => 1, 'void_reason' => 'مرفق مكرر'])->assertOk();
        $audit = $this->history(['entity' => 'visit_attachment'])->assertOk()->assertJsonPath('meta.total', 2)->json();
        $this->assertSame('voided', $audit['data'][0]['action']);
        $private = DB::table('visit_attachments')->where('id', $id)->value('storage_key');
        $this->assertStringNotContainsString($private, json_encode($audit));
        $before = $this->history()->assertOk()->json('meta.total');
        $request = Request::create('/api/dossiers/report');
        $request->setUserResolver(fn () => $this->f['user']);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $doc = app(DossierReports::class)->document($request, $f, [], $this->s['id']);
        $attachment = collect($doc['sections'])->keyBy('title')['بيانات المرفقات']['rows'][0];
        $this->assertStringContainsString('مرفق مكرر', $attachment['name']);
        $this->assertStringNotContainsString($private, json_encode($doc));
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', DB::table('permissions')->where('code', 'dossiers.attachments.view')->value('id'))->delete();
        $this->history(['entity' => 'visit_attachment'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->history(['entity' => 'dossier_upload'])->assertOk()->assertJsonPath('meta.total', 0);
        $result = $this->history()->assertOk()->json();
        $this->assertLessThan($before, $result['meta']['total']);
        $this->assertArrayNotHasKey('visit_attachment', $result['filters']['entities']);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $doc = app(DossierReports::class)->document($request, $f, [], $this->s['id']);
        $this->assertNotContains('بيانات المرفقات', array_column($doc['sections'], 'title'));
    }

    public function test_facility_local_date_boundaries_and_definition_seeder_without_automatic_grants(): void
    {
        DB::table('facilities')->where('id', $this->f['facility'])->update(['timezone' => 'Asia/Damascus']);
        $before = DB::table('role_permissions')->count();
        (new DossierAuditPermissionsSeeder)->run();
        (new DossierAuditPermissionsSeeder)->run();
        $this->assertSame($before, DB::table('role_permissions')->count());
        $this->assertSame(1, DB::table('permissions')->where('code', 'dossiers.audit')->count());
        $start = CarbonImmutable::parse('2020-02-02', 'Asia/Damascus')->setTimezone(config('app.timezone'));
        $this->event(['occurred_at' => $start->subSecond()->format('Y-m-d H:i:s')]);
        $id = $this->event(['occurred_at' => $start->format('Y-m-d H:i:s')]);
        $this->event(['occurred_at' => $start->addDay()->format('Y-m-d H:i:s')]);
        $this->history(['from' => '2020-02-02', 'to' => '2020-02-02'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        DB::table('facilities')->where('id', $this->f['facility'])->update(['is_active' => false]);
        $this->history()->assertForbidden();
    }

    public function test_large_history_uses_bounded_page_queries_without_join_multiplication(): void
    {
        $base = ['facility_id' => $this->f['facility'], 'actor_id' => $this->f['user']->id, 'entity_type' => 'dossier_visit', 'entity_id' => $this->s['visit']['id'], 'event' => 'saved', 'old_values' => null, 'new_values' => json_encode(['visit_date' => '2001-03-02']), 'request_id' => (string) Str::uuid(), 'occurred_at' => '2020-02-02 12:00:00'];
        for ($i = 0; $i < 24; $i++) {
            DB::table('audit_logs')->insert(array_fill(0, 500, $i < 4 ? $base : array_replace($base, ['entity_id' => 99999999])));
        }
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'audit');
        $service = app(DossierAuditHistory::class);
        $counts = [];
        foreach ([20, 100] as $size) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $data = $service->listing($f, $this->s['id'], ['from' => '2020-02-02', 'to' => '2020-02-02', 'per_page' => $size]);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame(2000, $data['meta']['total']);
            $this->assertCount($size, $data['data']);
            $this->assertCount($size, array_unique(array_column($data['data'], 'id')));
        }
        $this->assertSame($counts[0], $counts[1]);
        $this->assertLessThanOrEqual(8, $counts[1]);
        $q = $service->query($f, $this->s['id'], [])->orderByDesc('a.occurred_at')->orderByDesc('a.id')->limit(20);
        $plan = DB::select('EXPLAIN '.$q->toSql(), $q->getBindings());
        $audit = collect($plan)->first(fn ($row) => $row->table === 'a');
        $this->assertNotNull($audit->possible_keys);
        $this->assertNotNull($audit->key, 'An existing audit index should be selected for this realistic fixture');
    }

    public function test_openapi_describes_the_scoped_history_and_existing_full_history_report(): void
    {
        $paths = $this->getJson('/docs/api.json')->assertOk()->json('paths');
        $audit = $paths['/api/dossiers/{dossier}/audit']['get'];
        $this->assertStringContainsString('BOTH dossiers.view and dossiers.audit', $audit['description']);
        $this->assertStringContainsString('dossiers.attachments.view', $audit['description']);
        $this->assertArrayHasKey('403', $audit['responses']);
        $this->assertArrayHasKey('data', $audit['responses']['200']['content']['application/json']['schema']['properties']);
        $parameters = array_column($audit['parameters'], 'name');
        foreach (['facility_id', 'from', 'to', 'visit_id', 'entity', 'action', 'page', 'per_page'] as $field) {
            $this->assertContains($field, $parameters);
        }
        $report = $paths['/api/dossiers/{dossier}/report/{format}']['post'];
        $this->assertStringContainsString('Full Patient Card history', $report['description']);
        $this->assertStringContainsString('actual visit_date', $report['description']);
    }

    public function test_full_report_includes_voided_facts_and_uses_actual_visit_date_range(): void
    {
        $this->s = $this->saveSection('clinical', ['services' => [['catalog_id' => $this->f['service'], 'note' => '=1+1'] + $this->context()], 'procedures' => [['catalog_id' => $this->f['procedure']] + $this->context()]])->assertOk()->json('data');
        $this->s = $this->saveSection('medications', ['prescription' => $this->rx(), 'outcome' => $this->outcome(['code' => 'DOS-REFER', 'referral_target' => 'وجهة اصطناعية', 'outgoing_referral_date' => '2001-03-02', 'outgoing_referral_reason' => 'سبب إحالة محفوظ'])])->assertOk()->json('data');
        DB::table('visit_services')->where('visit_id', $this->s['visit']['id'])->update(['voided_at' => now(), 'voided_by' => $this->f['user']->id, 'void_reason' => 'خدمة مسجلة بالخطأ']);
        foreach (['visit_diagnoses', 'visit_procedures', 'visit_outcomes', 'visit_prescriptions'] as $table) {
            DB::table($table)->where('visit_id', $this->s['visit']['id'])->update(['voided_at' => now(), 'voided_by' => $this->f['user']->id, 'void_reason' => 'سبب محفوظ '.$table]);
        }
        DB::table('visit_prescription_items')->where('id', $this->s['clinical']['prescription']['items'][0]['id'])->update(['voided_at' => now(), 'voided_by' => $this->f['user']->id, 'void_reason' => 'تصحيح بند الدواء']);
        DB::table('visits')->where('id', $this->s['visit']['id'])->update(['voided_at' => now(), 'voided_by' => $this->f['user']->id, 'void_reason' => 'زيارة مكررة', 'status' => 'void', 'created_at' => '2090-01-01']);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $request = Request::create('/api/dossiers/report');
        $request->setUserResolver(fn () => $this->f['user']);
        $reports = app(DossierReports::class);
        $doc = $reports->document($request, $f, ['from' => '2001-03-02', 'to' => '2001-03-02'], $this->s['id']);
        $sections = collect($doc['sections'])->keyBy('title');
        $this->assertCount(1, $sections['التسلسل الزمني للزيارات']['rows']);
        $this->assertStringContainsString('زيارة مكررة', $sections['التسلسل الزمني للزيارات']['rows'][0]['status']);
        $this->assertStringContainsString('خدمة مسجلة بالخطأ', $sections['الخدمات']['rows'][0]['note']);
        foreach (['التشخيصات' => 'visit_diagnoses', 'الإجراءات' => 'visit_procedures', 'النتائج والإحالات الصادرة' => 'visit_outcomes'] as $title => $table) {
            $this->assertCount(1, $sections[$title]['rows']);
            $this->assertStringContainsString('سبب محفوظ '.$table, $sections[$title]['rows'][0]['note']);
        }
        $this->assertStringContainsString('سبب إحالة محفوظ', $sections['النتائج والإحالات الصادرة']['rows'][0]['note']);
        $this->assertCount(2, $sections['الأدوية الموصوفة']['rows']);
        foreach ($sections['الأدوية الموصوفة']['rows'] as $row) {
            $this->assertStringContainsString('سبب محفوظ visit_prescriptions', $row['note']);
        }
        $this->assertStringContainsString('تصحيح بند الدواء', $sections['الأدوية الموصوفة']['rows'][0]['note']);
        $excluded = $reports->document($request, $f, ['from' => '2001-03-03'], $this->s['id']);
        $this->assertCount(0, collect($excluded['sections'])->keyBy('title')['الخدمات']['rows']);
        $this->callApi('POST', "/{$this->s['id']}/report/pdf")->assertOk();
        $bytes = $this->callApi('POST', "/{$this->s['id']}/report/xlsx")->assertOk()->getContent();
        $path = tempnam(storage_path('framework/testing'), 'closure-');
        file_put_contents($path, $bytes);
        try {
            $book = IOFactory::load($path);
            $found = false;
            foreach ($book->getAllSheets() as $sheet) {
                $this->assertTrue($sheet->getRightToLeft());
                foreach ($sheet->getCoordinates() as $coordinate) {
                    $cell = $sheet->getCell($coordinate);
                    $this->assertNotSame('f', $cell->getDataType());
                    if (str_contains((string) $cell->getValue(), '=1+1')) {
                        $found = true;
                        $this->assertSame('s', $cell->getDataType());
                    }
                }
            }
            $this->assertTrue($found);
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }
}
