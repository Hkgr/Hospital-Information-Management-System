<?php

namespace Tests\Feature;

use App\Services\Dossiers\DossierVisitWriter;
use App\Services\Dossiers\Imports\ImportBundle;
use App\Services\Dossiers\Imports\ImportWorkbook;
use Database\Seeders\DossierAuditPermissionsSeeder;
use Database\Seeders\DossierImportPermissionsSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DossierCompletionFixture;
use Tests\TestCase;

class DossierImportTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = DossierCompletionFixture::make();
        app(DossierImportPermissionsSeeder::class)->run();
        foreach (array_keys(DossierImportPermissionsSeeder::CODES) as $code) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        $this->token = $this->f['user']->createToken('import-test', ['api'])->plainTextToken;
        Storage::fake('dossier_private');
    }

    private function api(string $method, string $url, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/dossiers'.$url, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function patient(array $replace = []): array
    {
        return $replace + ['source_record_id' => 'patient-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'opening_date' => '2000-01-01', 'first_name' => 'مريض اصطناعي', 'family_name' => 'للاستيراد', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'];
    }

    private function file(array $sheets): UploadedFile
    {
        $book = app(ImportWorkbook::class)->template(['id' => $this->f['facility']], 'legacy_migration', '2026-09-01', []);
        foreach ($sheets as $name => $rows) {
            foreach ($rows as $i => $row) {
                foreach (ImportWorkbook::SHEETS[$name] as $j => $key) {
                    if (isset($row[$key])) {
                        $value = $row[$key];
                        $book->getSheetByName($name)->setCellValueExplicit([$j + 1, $i + 3], $value, is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'import-test-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->beforeApplicationDestroyed(fn () => is_file($path) ? unlink($path) : null);

        return new UploadedFile($path, 'patients.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function upload(array $sheets): array
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])->post('/api/dossiers/imports', ['facility_id' => $this->f['facility'], 'purpose' => 'legacy_migration', 'cutover_date' => '2026-09-01', 'file' => $this->file($sheets)])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    private function step(array $batch, string $op): array
    {
        return $this->api('POST', '/imports/'.$batch['id'].'/'.$op, ['lock_version' => $batch['lock_version'], 'confirm' => true])->assertOk()->json('data');
    }

    private function replaySheets(): array
    {
        $context = ['local_visit_ref' => 'L1', 'clinic_id' => $this->f['clinics'][0], 'doctor_id' => $this->f['workflow_doctors'][0]];

        return ['Patients' => [$this->patient()],
            'Visits' => [['source_record_id' => 'V-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'local_visit_ref' => 'L1', 'visit_date' => '2001-02-03', 'visit_type_id' => $this->f['visit_type'], 'is_referred' => 0]],
            'Diagnoses' => [['source_record_id' => 'DX-'.$this->f['tag'], 'local_visit_ref' => 'L1', 'diagnosis_id' => $this->f['diagnosis'], 'clinic_id' => $context['clinic_id'], 'diagnosing_staff_id' => $context['doctor_id']]],
            'Services' => [$context + ['source_record_id' => 'S-'.$this->f['tag'], 'catalog_id' => $this->f['service']]],
            'Procedures' => [$context + ['source_record_id' => 'PR-'.$this->f['tag'], 'catalog_id' => $this->f['procedure']]],
            'Prescriptions' => [['source_record_id' => 'RX-'.$this->f['tag'], 'local_visit_ref' => 'L1', 'prescribing_clinic_id' => $context['clinic_id'], 'prescribing_staff_id' => $context['doctor_id'], 'prescribed_on' => '2001-02-03']],
            'Medications' => [['source_record_id' => 'M-'.$this->f['tag'], 'local_visit_ref' => 'L1', 'medication_id' => $this->f['medication'], 'display_order' => 1]],
            'Outcomes' => [$context + ['source_record_id' => 'O-'.$this->f['tag'], 'code' => 'DOS-RX', 'outcome_on' => '2001-02-03']],
        ];
    }

    private function clinicalCounts(): array
    {
        return collect(['patients', 'patient_dossiers', 'visits', 'visit_diagnoses', 'visit_services', 'visit_procedures', 'visit_prescriptions', 'visit_prescription_items', 'visit_outcomes', 'visit_medications', 'oncology_plans', 'blood_bank_events'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }

    public static function replayConflicts(): array
    {
        return array_map(fn ($case) => [$case], ['Diagnoses', 'Services', 'Procedures', 'Outcomes', 'Prescriptions', 'Medications', 'new-prescription', 'new-medication', 'other-patient', 'other-dossier', 'other-visit']);
    }

    #[DataProvider('replayConflicts')]
    public function test_replay_ownership_conflicts_require_review_without_any_clinical_writes(string $case): void
    {
        $sheets = $this->replaySheets();
        $first = $this->step($this->step($this->upload($sheets), 'validate'), 'commit');
        $this->assertSame('completed', $first['status']);
        $counts = $this->clinicalCounts();
        if (in_array($case, ['new-prescription', 'new-medication'])) {
            $sheets[$case === 'new-prescription' ? 'Prescriptions' : 'Medications'][0]['source_record_id'] .= '-new';
        } elseif ($case === 'other-patient') {
            $sheets['Patients'][0]['source_record_id'] .= '-other';
            $sheets['Visits'][0]['source_record_id'] .= '-new';
        } elseif ($case === 'other-dossier') {
            $sheets['Patients'][0]['source_record_id'] .= '-other';
        } elseif ($case === 'other-visit') {
            $other = ['Patients' => $sheets['Patients'], 'Visits' => $sheets['Visits']];
            $other['Visits'][0]['source_record_id'] .= '-other';
            $this->assertSame('completed', $this->step($this->step($this->upload($other), 'validate'), 'commit')['status']);
            $counts = $this->clinicalCounts();
            $sheets['Visits'] = $other['Visits'];
        } else {
            $sheets['Visits'][0]['source_record_id'] .= '-new';
            foreach ($sheets as $sheet => &$rows) {
                if (! in_array($sheet, ['Patients', 'Visits', $case])) {
                    $rows[0]['source_record_id'] .= '-new';
                }
            }
            unset($rows);
        }
        $batch = $this->step($this->upload($sheets), 'validate');
        $this->assertSame($counts, $this->clinicalCounts());
        if ($batch['status'] === 'validated') {
            // Exercise the reviewed behavior all the way through its writer, too.
            $this->step($batch, 'commit');
        }
        $this->assertSame($counts, $this->clinicalCounts(), 'A replay conflict must never reach a clinical writer');
        $this->assertSame('needs_review', $batch['status'], $case);
        $this->assertSame(8, $batch['counts']['needs_review']);
        $this->api('POST', '/imports/'.$batch['id'].'/commit', ['lock_version' => $batch['lock_version'], 'confirm' => true])->assertConflict();
        $this->assertSame($counts, $this->clinicalCounts(), 'No partial person, visit or fact after rejected commit');
    }

    public static function replayReferences(): array
    {
        return [['L1'], ['0']];
    }

    #[DataProvider('replayReferences')]
    public function test_replay_exact_clinical_hierarchy_skips_every_writer_but_new_same_day_sources_are_allowed(string $reference): void
    {
        $sheets = $this->replaySheets();
        foreach ($sheets as $sheet => &$rows) {
            if ($sheet !== 'Patients') {
                $rows[0]['local_visit_ref'] = $reference;
            }
        }
        unset($rows);
        $first = $this->step($this->step($this->upload($sheets), 'validate'), 'commit');
        $this->assertSame('completed', $first['status']);
        $counts = $this->clinicalCounts();
        // Different file bytes, identical parsed source values.
        $sheets['Patients'][0]['phone'] = '';
        $again = $this->step($this->step($this->upload($sheets), 'validate'), 'commit');
        $this->assertSame('completed', $again['status'], json_encode($again));
        $this->assertSame(8, $again['counts']['skipped']);
        $this->assertSame($counts, $this->clinicalCounts());
        foreach ($sheets as $sheet => &$rows) {
            if ($sheet !== 'Patients') {
                $rows[0]['source_record_id'] .= '-new';
            }
        }
        unset($rows);
        $next = $this->step($this->step($this->upload($sheets), 'validate'), 'commit');
        $this->assertSame('completed', $next['status'], json_encode($next));
        $this->assertSame(1, $next['counts']['skipped']);
        foreach (['visits', 'visit_diagnoses', 'visit_services', 'visit_procedures', 'visit_prescriptions', 'visit_prescription_items', 'visit_outcomes'] as $table) {
            $counts[$table]++;
        }
        $this->assertSame($counts, $this->clinicalCounts());
    }

    public function test_replay_commit_rechecks_authoritative_source_row_not_only_ledger_fingerprint(): void
    {
        $sheets = $this->replaySheets();
        $first = $this->step($this->step($this->upload($sheets), 'validate'), 'commit');
        $original = DB::table('dossier_import_rows')->where('batch_id', $first['id'])->where('sheet', 'Services')->first();
        foreach ($sheets as $sheet => &$rows) {
            if ($sheet !== 'Patients') {
                $rows[0]['source_record_id'] .= '-new';
            }
        }
        unset($rows);
        $batch = $this->step($this->upload($sheets), 'validate');
        $this->assertSame('validated', $batch['status']);
        $counts = $this->clinicalCounts();
        $current = DB::table('dossier_import_rows')->where('batch_id', $batch['id'])->where('sheet', 'Services')->first();
        // Introduce unprovable ownership after preview. FK is valid, source identity is not.
        DB::table('dossier_import_sources')->insert(['facility_id' => $this->f['facility'], 'sheet' => 'Services', 'source_record_id' => $current->source_record_id, 'fingerprint' => $current->fingerprint, 'row_id' => $original->id, 'created_at' => now(), 'updated_at' => now()]);
        $batch = $this->step($batch, 'commit');
        $this->assertSame($counts, $this->clinicalCounts());
        $this->assertSame('completed_with_errors', $batch['status']);
        $this->assertSame(8, $batch['counts']['needs_review']);
    }

    public function test_import_audit_api_exposes_allowlisted_provenance_and_separate_identity_assignments(): void
    {
        (new DossierAuditPermissionsSeeder)->run();
        $permission = DB::table('permissions')->where('code', 'dossiers.audit')->value('id');
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => $permission]);
        $paper = '000-'.$this->f['tag'];
        $alias = 'LEG-'.$this->f['tag'];
        $batch = $this->step($this->step($this->upload(['Patients' => [$this->patient(['paper_file_number' => $paper, 'legacy_code' => $alias]), $this->patient(['source_record_id' => 'other-'.$this->f['tag'], 'local_patient_ref' => 'P2'])]]), 'validate'), 'commit');
        $this->assertSame('completed', $batch['status']);
        $row = $batch['rows']['data'][0];
        $id = $row['dossier_id'];
        $dossier = DB::table('patient_dossiers')->find($id);
        $canonical = DB::table('patients')->where('id', $dossier->patient_id)->value('patient_code');
        $this->assertStringStartsWith('PC-', $canonical);
        $this->assertSame($alias, $dossier->code);
        $audit = $this->api('GET', "/$id/audit", ['action' => 'imported'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.action_label', 'اعتماد استيراد')->json('data.0');
        $changes = array_column($audit['changes'], 'after', 'field');
        $this->assertSame(['import_batch_id' => (string) $batch['id'], 'source_rows' => (string) $row['id'], 'purpose' => 'legacy_migration', 'cutover_date' => '2026-09-01'], $changes);
        $history = $this->api('GET', "/$id/audit", ['per_page' => 50])->assertOk()->json('data');
        foreach (['paper_file_number' => $paper, 'code' => $alias] as $field => $value) {
            $assignments = collect($history)->where('action', 'updated')->filter(fn ($event) => collect($event['changes'])->contains(fn ($change) => $change['field'] === $field && $change['after'] === $value && $change['before_recorded']));
            $this->assertCount(1, $assignments, $field);
        }
        $created = collect($history)->where('action', 'created');
        $this->assertCount(2, $created);
        $this->assertTrue($created->contains(fn ($e) => collect($e['changes'])->contains(fn ($c) => $c['field'] === 'first_name' && $c['after'] === 'مريض اصطناعي')));
        $this->assertSame($canonical, DB::table('patients')->where('id', $dossier->patient_id)->value('patient_code'));
        foreach (['encrypted_payload', 'private_path', 'fingerprint', 'file_hash', 'storage_key'] as $hidden) {
            $this->assertStringNotContainsString($hidden, json_encode($history));
        }
        $this->api('GET', "/$id/audit", ['facility_id' => $this->f['other']])->assertForbidden();
        $this->api('GET', '/imports/'.$batch['id'], ['facility_id' => $this->f['other']])->assertForbidden();
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
        $this->api('GET', "/$id/audit")->assertForbidden();
    }

    public function test_legacy_onboarding_has_no_invented_visit_and_is_retry_safe(): void
    {
        $patients = DB::table('patients')->count();
        $visits = DB::table('visits')->count();
        $b = $this->upload(['Patients' => [$this->patient(['legacy_code' => 'LEG-'.$this->f['tag'], 'phone' => '001234567', 'birth_date' => '1980', 'birth_date_accuracy' => 'year_only'])]]);
        $this->assertSame($patients, DB::table('patients')->count());
        $b = $this->step($b, 'validate');
        $this->assertSame('validated', $b['status'], json_encode($b));
        $this->assertSame($patients, DB::table('patients')->count());
        $b = $this->step($b, 'commit');
        $this->assertSame('completed', $b['status'], json_encode($b));
        $d = DB::table('patient_dossiers')->where('id', $b['rows']['data'][0]['dossier_id'])->first();
        $p = DB::table('patients')->find($d->patient_id);
        $this->assertSame('2000-01-01', $d->opening_date);
        $this->assertSame('draft', $d->status);
        $this->assertNull($d->registration_visit_id);
        $this->assertSame($visits, DB::table('visits')->count());
        $this->assertStringStartsWith('PC-', $p->patient_code);
        $this->assertSame('001234567', $p->phone);
        $this->assertSame('year_only', $p->birth_date_accuracy);
        $this->assertSame('1980-01-01', $p->birth_date);
        $this->api('POST', '/imports/'.$b['id'].'/commit', ['lock_version' => $b['lock_version'] - 1, 'confirm' => true])->assertConflict();
        $this->assertSame($patients + 1, DB::table('patients')->count());
        $raw = DB::table('dossier_import_rows')->where('batch_id', $b['id'])->value('encrypted_payload');
        $this->assertStringNotContainsString('001234567', $raw);
        $this->api('GET', '/imports/'.$b['id'])->assertDontSee('encrypted_payload')->assertDontSee('private_path')->assertDontSee('001234567');
    }

    public function test_explicit_same_day_visits_are_distinct_drafts_and_source_replay_skips_them(): void
    {
        $p = $this->patient();
        $visits = [];
        foreach ([1, 2] as $i) {
            $visits[] = ['source_record_id' => 'V'.$i.'-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'local_visit_ref' => 'V'.$i, 'visit_date' => '2001-02-03', 'visit_type_id' => $this->f['visit_type'], 'is_referred' => 0];
        }
        $b = $this->step($this->upload(['Patients' => [$p], 'Visits' => $visits]), 'validate');
        $this->assertSame('validated', $b['status'], json_encode($b));
        $b = $this->step($b, 'commit');
        $this->assertSame('completed', $b['status'], json_encode($b));
        $id = $b['rows']['data'][0]['dossier_id'];
        $this->assertSame(2, DB::table('visits')->where('dossier_id', $id)->count());
        $this->assertSame(['draft'], DB::table('visits')->where('dossier_id', $id)->distinct()->pluck('status')->all());
        // A new file with the same stable sources plus another patient is a new batch.
        $again = $this->step($this->upload(['Patients' => [$p, $this->patient(['source_record_id' => 'P2-'.$this->f['tag'], 'local_patient_ref' => 'P2'])], 'Visits' => $visits]), 'validate');
        $this->assertSame('validated', $again['status'], json_encode($again));
        $again = $this->step($again, 'commit');
        $this->assertSame('completed', $again['status'], json_encode($again));
        $this->assertSame(3, $again['counts']['skipped']);
        $this->assertSame(2, DB::table('visits')->where('dossier_id', $id)->count());
    }

    public function test_future_visit_blocks_its_entire_patient_bundle_but_other_patient_can_commit(): void
    {
        $b = $this->upload(['Patients' => [$this->patient(), $this->patient(['source_record_id' => 'P2-'.$this->f['tag'], 'local_patient_ref' => 'P2'])], 'Visits' => [['source_record_id' => 'V-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'local_visit_ref' => 'V1', 'visit_date' => '2999-01-01', 'visit_type_id' => $this->f['visit_type'], 'is_referred' => 0]]]);
        $b = $this->step($b, 'validate');
        $this->assertSame(2, $b['counts']['needs_review']);
        $b = $this->step($b, 'commit');
        $this->assertSame('completed_with_errors', $b['status']);
        $this->assertSame(1, $b['counts']['committed']);
    }

    public function test_scope_and_import_permissions_are_enforced_before_file_storage(): void
    {
        $this->api('POST', '/imports', ['facility_id' => 999999999])->assertForbidden();
        $permission = DB::table('permissions')->where('code', 'dossiers.import.create')->value('id');
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
        $this->api('POST', '/imports')->assertForbidden();
        $this->assertSame([], Storage::disk('dossier_private')->allFiles());
    }

    public function test_template_is_private_rtl_cairo_and_contains_no_patient_data(): void
    {
        $response = $this->api('GET', '/import-template.xlsx', ['purpose' => 'offline_capture', 'cutover_date' => '2026-09-01'])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'import-template-');
        file_put_contents($path, $response->streamedContent());
        try {
            $book = IOFactory::load($path);
            $this->assertCount(10, $book->getAllSheets());
            foreach ($book->getAllSheets() as $s) {
                $this->assertTrue($s->getRightToLeft());
                $this->assertSame('Cairo', $s->getStyle('A1')->getFont()->getName());
            }
            $this->assertNull($book->getSheetByName('Patients')->getCell('A3')->getValue());
            $this->assertSame('source_record_id', $book->getSheetByName('Patients')->getCell('A1')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_all_supported_facts_use_existing_writers_and_never_create_dispensing_or_oncology(): void
    {
        $before = [];
        foreach (['visit_medications', 'oncology_plans', 'blood_bank_events'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $v = ['source_record_id' => 'V-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'local_visit_ref' => 'V1', 'visit_date' => '2001-02-03', 'visit_type_id' => $this->f['visit_type'], 'is_referred' => 0];
        $context = ['local_visit_ref' => 'V1', 'clinic_id' => $this->f['clinics'][0], 'doctor_id' => $this->f['workflow_doctors'][0]];
        $sheets = ['Patients' => [$this->patient()], 'Visits' => [$v],
            'Diagnoses' => [['source_record_id' => 'DX-'.$this->f['tag'], 'local_visit_ref' => 'V1', 'diagnosis_id' => $this->f['diagnosis'], 'clinic_id' => $context['clinic_id'], 'diagnosing_staff_id' => $context['doctor_id']]],
            'Services' => [array_merge($context, ['source_record_id' => 'S-'.$this->f['tag'], 'catalog_id' => $this->f['service'], 'note' => '=HYPERLINK("synthetic")'])],
            'Procedures' => [array_merge($context, ['source_record_id' => 'PR-'.$this->f['tag'], 'catalog_id' => $this->f['procedure']])],
            'Prescriptions' => [['source_record_id' => 'RX-'.$this->f['tag'], 'local_visit_ref' => 'V1', 'prescribing_clinic_id' => $context['clinic_id'], 'prescribing_staff_id' => $context['doctor_id'], 'prescribed_on' => '2001-02-03']],
            'Medications' => [['source_record_id' => 'M-'.$this->f['tag'], 'local_visit_ref' => 'V1', 'medication_id' => $this->f['medication'], 'display_order' => 1]],
            'Outcomes' => [array_merge($context, ['source_record_id' => 'O-'.$this->f['tag'], 'code' => 'DOS-RX', 'outcome_on' => '2001-02-03'])],
        ];
        $b = $this->step($this->upload($sheets), 'validate');
        $this->assertSame('validated', $b['status'], json_encode($b));
        $b = $this->step($b, 'commit');
        $this->assertSame('completed', $b['status'], json_encode($b));
        $visit = $b['rows']['data'][1]['visit_id'];
        foreach (['visit_diagnoses', 'visit_services', 'visit_procedures', 'visit_prescriptions', 'visit_outcomes'] as $table) {
            $this->assertSame(1, DB::table($table)->where('visit_id', $visit)->count(), $table);
        }
        $this->assertSame('=HYPERLINK("synthetic")', DB::table('visit_services')->where('visit_id', $visit)->value('note'));
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public function test_references_are_rechecked_at_commit_and_bundle_is_atomic(): void
    {
        $patients = DB::table('patients')->count();
        $b = $this->step($this->upload(['Patients' => [$this->patient()], 'Visits' => [['source_record_id' => 'V-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'local_visit_ref' => 'V1', 'visit_date' => '2001-02-03', 'visit_type_id' => $this->f['visit_type'], 'is_referred' => 0]]]), 'validate');
        DB::table('visit_types')->where('id', $this->f['visit_type'])->update(['is_active' => false]);
        $b = $this->step($b, 'commit');
        $this->assertSame('completed_with_errors', $b['status']);
        $this->assertSame($patients, DB::table('patients')->count());
        $this->assertSame(2, $b['counts']['needs_review']);
    }

    public function test_existing_patient_is_reused_without_overwriting_fields(): void
    {
        $p = DB::table('patients')->find($this->f['patients'][1]);
        $d = DB::table('patient_dossiers')->where('facility_id', $this->f['facility'])->where('patient_id', $p->id)->first();
        $data = ['source_record_id' => 'existing-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'patient_code' => $p->patient_code, 'opening_date' => $d->opening_date];
        $b = $this->step($this->upload(['Patients' => [$data]]), 'validate');
        $this->assertSame('validated', $b['status'], json_encode($b));
        $b = $this->step($b, 'commit');
        $this->assertSame($d->id, $b['rows']['data'][0]['dossier_id']);
        $this->assertEquals($p, DB::table('patients')->find($p->id));
        $bad = $this->step($this->upload(['Patients' => [$data + ['first_name' => 'لا تستبدل الاسم']]]), 'validate');
        $this->assertSame('needs_review', $bad['status']);
        $this->assertEquals($p, DB::table('patients')->find($p->id));
    }

    public function test_changed_patient_version_requires_review_instead_of_writing(): void
    {
        $p = DB::table('patients')->find($this->f['patients'][1]);
        $d = DB::table('patient_dossiers')->where('facility_id', $this->f['facility'])->where('patient_id', $p->id)->first();
        $b = $this->step($this->upload(['Patients' => [['source_record_id' => 'version-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'patient_code' => $p->patient_code, 'opening_date' => $d->opening_date]]]), 'validate');
        DB::table('patients')->where('id', $p->id)->increment('lock_version');
        $b = $this->step($b, 'commit');
        $this->assertSame('completed_with_errors', $b['status']);
        $this->assertSame(1, $b['counts']['needs_review']);
    }

    public function test_excel_formulas_are_rejected_but_formula_like_text_is_preserved(): void
    {
        $file = $this->file(['Patients' => [$this->patient()]]);
        $book = IOFactory::load($file->getRealPath());
        $book->getSheetByName('Patients')->setCellValue('F3', '=1+1');
        (new Xlsx($book))->save($file->getRealPath());
        $book->disconnectWorksheets();
        $this->expectException(ValidationException::class);
        app(ImportWorkbook::class)->read($file->getRealPath(), $this->f['facility'], 'legacy_migration', '2026-09-01');
    }

    public function test_duplicate_local_references_and_orphans_are_rejected_before_batch_storage(): void
    {
        $file = $this->file(['Patients' => [$this->patient()], 'Services' => [['source_record_id' => 'orphan', 'local_visit_ref' => 'missing', 'catalog_id' => $this->f['service']]]]);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])->post('/api/dossiers/imports', ['facility_id' => $this->f['facility'], 'purpose' => 'legacy_migration', 'cutover_date' => '2026-09-01', 'file' => $file])->assertUnprocessable();
        $this->assertSame(0, DB::table('dossier_import_batches')->where('facility_id', $this->f['facility'])->count());
        $this->assertSame([], Storage::disk('dossier_private')->allFiles());
    }

    public function test_cleanup_is_explicit_and_preserves_clinical_and_provenance_rows(): void
    {
        $b = $this->step($this->step($this->upload(['Patients' => [$this->patient()]]), 'validate'), 'commit');
        DB::table('dossier_import_batches')->where('id', $b['id'])->update(['file_expires_at' => now()->subDay()]);
        $path = DB::table('dossier_import_batches')->where('id', $b['id'])->value('private_path');
        $this->artisan('dossiers:cleanup-imports')->assertSuccessful();
        Storage::disk('dossier_private')->assertExists($path);
        $this->artisan('dossiers:cleanup-imports', ['--apply' => true, '--actor' => $this->f['user']->id])->assertSuccessful();
        Storage::disk('dossier_private')->assertMissing($path);
        $this->assertDatabaseHas('patient_dossiers', ['id' => $b['rows']['data'][0]['dossier_id']]);
        $this->assertDatabaseHas('dossier_import_rows', ['batch_id' => $b['id'], 'status' => 'committed']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'dossier_import_batch', 'entity_id' => $b['id'], 'event' => 'source_expired']);
    }

    public function test_rollback_refuses_before_any_ddl_after_upload_history_exists(): void
    {
        $this->upload(['Patients' => [$this->patient()]]);
        $migration = require database_path('migrations/2026_09_21_000003_add_dossier_import_batches.php');
        try {
            $migration->down();
            $this->fail('Expected history-preserving rollback refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('before changing schema', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('dossier_import_sources'));
    }

    public function test_validation_preloads_references_instead_of_reading_them_per_patient(): void
    {
        $reads = 0;
        DB::listen(function ($q) use (&$reads) {
            if (preg_match('/^select\b/i', $q->sql)) {
                $reads++;
            }
        });
        $counts = [];
        foreach ([1, 10] as $size) {
            $people = [];
            $visits = [];
            for ($i = 1; $i <= $size; $i++) {
                $people[] = $this->patient(['source_record_id' => "P$size-$i-".$this->f['tag'], 'local_patient_ref' => "P$i"]);
                $visits[] = ['source_record_id' => "V$size-$i-".$this->f['tag'], 'local_patient_ref' => "P$i", 'local_visit_ref' => "V$i", 'visit_date' => '2001-02-03', 'visit_type_id' => $this->f['visit_type'], 'is_referred' => 0];
            }
            $b = $this->upload(['Patients' => $people, 'Visits' => $visits]);
            $reads = 0;
            $b = $this->step($b, 'validate');
            $counts[] = $reads;
            $this->assertSame('validated', $b['status']);
        }
        file_put_contents(storage_path('framework/testing/import-query-counts.json'), json_encode(['one_patient_selects' => $counts[0], 'ten_patient_selects' => $counts[1]]));
        $this->assertLessThanOrEqual($counts[0] + 3, $counts[1], json_encode($counts));
    }

    public function test_unknown_template_and_wrong_facility_metadata_are_rejected(): void
    {
        foreach (['version', 'facility', 'macro', 'external', 'xml'] as $case) {
            $file = $this->file(['Patients' => [$this->patient()]]);
            $zip = new \ZipArchive;
            $zip->open($file->getRealPath());
            if ($case === 'macro') {
                $zip->addFromString('xl/vbaProject.bin', 'not a macro, but forbidden');
            }
            if ($case === 'external') {
                $zip->addFromString('xl/externalLinks/externalLink1.xml', '<xml/>');
            }
            if ($case === 'xml') {
                $zip->addFromString('xl/worksheets/sheet2.xml', '<!DOCTYPE x [<!ENTITY x "private">]><x/>');
            }
            $zip->close();
            if (in_array($case, ['version', 'facility'])) {
                $book = IOFactory::load($file->getRealPath());
                $book->getSheetByName('Instructions')->setCellValue($case === 'version' ? 'B1' : 'B2', $case === 'version' ? 'future-999' : '999999999');
                (new Xlsx($book))->save($file->getRealPath());
                $book->disconnectWorksheets();
            }
            $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])->post('/api/dossiers/imports', ['facility_id' => $this->f['facility'], 'purpose' => 'legacy_migration', 'cutover_date' => '2026-09-01', 'file' => $file])->assertUnprocessable();
        }
        $this->assertSame([], Storage::disk('dossier_private')->allFiles());
    }

    public function test_same_file_is_one_batch_and_changed_committed_source_is_not_overwritten(): void
    {
        $file = $this->file(['Patients' => [$this->patient()]]);
        $send = fn () => $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])->post('/api/dossiers/imports', ['facility_id' => $this->f['facility'], 'purpose' => 'legacy_migration', 'cutover_date' => '2026-09-01', 'file' => $file])->assertCreated()->json('data');
        $one = $send();
        $two = $send();
        $this->assertSame($one['id'], $two['id']);
        $one = $this->step($this->step($one, 'validate'), 'commit');
        $changed = $this->step($this->upload(['Patients' => [$this->patient(['first_name' => 'محتوى مختلف'])]]), 'validate');
        $this->assertSame('needs_review', $changed['status']);
        $this->assertArrayHasKey('source_record_id', $changed['rows']['data'][0]['errors']);
        $this->assertSame(1, DB::table('dossier_import_sources')->where('facility_id', $this->f['facility'])->count());
    }

    public function test_excel_date_cells_round_trip_and_leading_zero_text_stays_literal(): void
    {
        $file = $this->file(['Patients' => [$this->patient(['phone' => '000123', 'import_note' => '+SUM(1,2)'])]]);
        $book = IOFactory::load($file->getRealPath());
        $book->getSheetByName('Patients')->setCellValueExplicit('E3', Date::stringToExcel('2000-01-01'), DataType::TYPE_NUMERIC);
        $book->getSheetByName('Patients')->getStyle('E3')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        (new Xlsx($book))->save($file->getRealPath());
        $book->disconnectWorksheets();
        $rows = app(ImportWorkbook::class)->read($file->getRealPath(), $this->f['facility'], 'legacy_migration', '2026-09-01');
        $this->assertSame('2000-01-01', $rows[0]['data']['opening_date']);
        $this->assertSame('000123', $rows[0]['data']['phone']);
        $this->assertSame('+SUM(1,2)', $rows[0]['data']['import_note']);
    }

    public function test_existing_global_patient_can_get_a_new_facility_context_without_an_invented_visit(): void
    {
        $p = (array) DB::table('patients')->find($this->f['patients'][1]);
        unset($p['id']);
        $p['patient_code'] = 'GLOBAL-'.$this->f['tag'];
        $id = DB::table('patients')->insertGetId($p);
        $permission = DB::table('permissions')->where('code', 'dossiers.visits.create')->value('id');
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
        $b = $this->step($this->step($this->upload(['Patients' => [['source_record_id' => 'context-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'patient_code' => $p['patient_code'], 'opening_date' => '1995-01-02']]]), 'validate'), 'commit');
        $this->assertSame('completed', $b['status'], json_encode($b));
        $this->assertDatabaseHas('patient_dossiers', ['patient_id' => $id, 'facility_id' => $this->f['facility'], 'opening_date' => '1995-01-02', 'registration_visit_id' => null]);
        $this->assertSame(0, DB::table('visits')->where('patient_id', $id)->count());
    }

    public function test_ambiguous_alias_and_canonical_alias_disagreement_never_merge_identities(): void
    {
        $alias = 'AMB-'.$this->f['tag'];
        DB::table('patient_dossiers')->where('id', $this->f['dossiers'][0])->update(['code' => $alias]);
        DB::table('patient_dossiers')->insert(['facility_id' => $this->f['other'], 'patient_id' => $this->f['patients'][2], 'code' => $alias, 'opening_date' => '2000-01-01', 'entered_by' => $this->f['user']->id]);
        $b = $this->step($this->upload(['Patients' => [['source_record_id' => 'amb-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'legacy_code' => $alias, 'opening_date' => '2000-01-01']]]), 'validate');
        $this->assertSame('needs_review', $b['status']);
        $this->assertSame(0, DB::table('dossier_import_sources')->where('facility_id', $this->f['facility'])->count());
        $this->api('POST', '/imports/'.$b['id'].'/commit', ['lock_version' => $b['lock_version'], 'confirm' => true])->assertConflict();
    }

    public function test_commit_rechecks_clinical_authorization_and_cancel_preserves_source_audit(): void
    {
        $b = $this->step($this->upload(['Patients' => [$this->patient()]]), 'validate');
        $permission = DB::table('permissions')->where('code', 'patients.create')->value('id');
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
        $this->api('POST', '/imports/'.$b['id'].'/commit', ['lock_version' => $b['lock_version'], 'confirm' => true])->assertForbidden();
        $cancelled = $this->step($b, 'cancel');
        $this->assertSame('cancelled', $cancelled['status']);
        $this->api('POST', '/imports/'.$b['id'].'/validate', ['lock_version' => $cancelled['lock_version']])->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'dossier_import_batch', 'entity_id' => $b['id'], 'event' => 'state_changed']);
    }

    public function test_failure_after_personal_write_rolls_back_the_bundle_and_does_not_reserve_its_paper_number(): void
    {
        $paper = 'PAPER-'.$this->f['tag'];
        $b = $this->step($this->upload(['Patients' => [$this->patient(['paper_file_number' => $paper]), $this->patient(['source_record_id' => 'second-'.$this->f['tag'], 'local_patient_ref' => 'P2', 'paper_file_number' => $paper])], 'Visits' => [['source_record_id' => 'visit-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'local_visit_ref' => 'V1', 'visit_date' => '2001-02-03', 'visit_type_id' => $this->f['visit_type'], 'is_referred' => 0]]]), 'validate');
        $this->mock(DossierVisitWriter::class)->shouldReceive('save')->once()->andThrow(ValidationException::withMessages(['visit_date' => 'Synthetic concurrent domain rejection.']));
        $b = $this->step($b, 'commit');
        $this->assertSame('completed_with_errors', $b['status']);
        $this->assertSame(2, $b['counts']['needs_review']);
        $this->assertSame(1, $b['counts']['committed']);
        $this->assertSame(1, DB::table('patients')->where('paper_file_number', $paper)->count());
        $this->assertSame(1, DB::table('dossier_import_sources')->where('facility_id', $this->f['facility'])->count());
    }

    public function test_error_workbook_reopens_with_safe_text_and_numeric_row_numbers(): void
    {
        $b = $this->step($this->upload(['Patients' => [$this->patient(['patient_code' => '=PRIVATE-SOURCE'])]]), 'validate');
        $response = $this->api('GET', '/imports/'.$b['id'].'/errors.xlsx')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $path = tempnam(sys_get_temp_dir(), 'import-errors-');
        file_put_contents($path, $response->streamedContent());
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertTrue($sheet->getRightToLeft());
            $this->assertSame('Cairo', $sheet->getStyle('A1')->getFont()->getName());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
            $this->assertSame(3, $sheet->getCell('B2')->getValue());
            foreach ($sheet->getCellCollection()->getCoordinates() as $cell) {
                $this->assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell($cell)->getDataType());
            }
            $this->assertStringNotContainsString('PRIVATE-SOURCE', json_encode($sheet->toArray()));
            $this->assertNotSame('', $sheet->getPageSetup()->getPrintArea());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->api('GET', '/imports/'.$b['id'].'/errors.xlsx', ['facility_id' => $this->f['other']])->assertForbidden();
    }

    public function test_invalid_signature_duplicate_sources_and_archive_bomb_are_rejected(): void
    {
        foreach (['signature', 'duplicate', 'cross_sheet', 'bomb', 'oversize'] as $kind) {
            $file = $this->file(['Patients' => $kind === 'duplicate' ? [$this->patient(), $this->patient(['local_patient_ref' => 'P2'])] : [$this->patient()]]);
            if ($kind === 'cross_sheet') {
                $file = $this->file(['Patients' => [$this->patient()], 'Visits' => [['source_record_id' => 'patient-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'local_visit_ref' => 'V1']]]);
            }
            if ($kind === 'signature') {
                file_put_contents($file->getRealPath(), 'not an XLSX file');
            }
            if ($kind === 'oversize') {
                file_put_contents($file->getRealPath(), str_repeat('x', ImportWorkbook::MAX_BYTES + 1));
            }
            if ($kind === 'bomb') {
                $zip = new \ZipArchive;
                $zip->open($file->getRealPath());
                $zip->addFromString('xl/bomb.xml', str_repeat('x', 2000000));
                $zip->close();
            }
            $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])->post('/api/dossiers/imports', ['facility_id' => $this->f['facility'], 'purpose' => 'legacy_migration', 'cutover_date' => '2026-09-01', 'file' => $file])->assertUnprocessable();
        }
        $this->assertSame([], Storage::disk('dossier_private')->allFiles());
    }

    public function test_unambiguous_alias_matches_but_disagreeing_canonical_code_requires_review(): void
    {
        $d = DB::table('patient_dossiers')->find($this->f['dossiers'][0]);
        $alias = 'ALIAS-'.$this->f['tag'];
        DB::table('patient_dossiers')->where('id', $d->id)->update(['code' => $alias]);
        $row = ['source_record_id' => 'alias-'.$this->f['tag'], 'local_patient_ref' => 'P1', 'legacy_code' => $alias, 'opening_date' => $d->opening_date];
        $b = $this->step($this->upload(['Patients' => [$row]]), 'validate');
        $this->assertSame('validated', $b['status']);
        $b = $this->step($b, 'commit');
        $this->assertSame($d->id, $b['rows']['data'][0]['dossier_id']);
        $row['source_record_id'] .= '-conflict';
        $row['patient_code'] = DB::table('patients')->where('id', $this->f['patients'][2])->value('patient_code');
        $b = $this->step($this->upload(['Patients' => [$row]]), 'validate');
        $this->assertSame('needs_review', $b['status']);
        $this->assertArrayHasKey('patient_code', $b['rows']['data'][0]['errors']);
    }

    public function test_database_failure_reports_only_safe_diagnostics_and_keeps_batch_resumable(): void
    {
        $b = $this->upload(['Patients' => [$this->patient()]]);
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (\RuntimeException $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });
        $this->mock(ImportBundle::class)->shouldReceive('prime')->once()->andThrow(new QueryException('mysql', 'select PRIVATE_SQL', ['PRIVATE_PATIENT_VALUE'], new \PDOException('PRIVATE_DRIVER_MESSAGE')));
        $response = $this->api('POST', '/imports/'.$b['id'].'/validate', ['lock_version' => $b['lock_version']])->assertStatus(500)->assertJsonPath('error.code', 'DOSSIERS_UNAVAILABLE');
        $response->assertDontSee('PRIVATE_')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(1, $reported);
        $this->assertNull($reported[0]->getPrevious());
        $this->assertStringNotContainsString('PRIVATE_', $reported[0]->getMessage());
        $this->assertDatabaseHas('dossier_import_batches', ['id' => $b['id'], 'status' => 'uploaded', 'lock_version' => $b['lock_version']]);
    }

    public function test_patient_and_cell_limits_are_enforced_before_persisting_any_batch(): void
    {
        $rows = [];
        for ($n = 1; $n <= ImportWorkbook::MAX_PATIENTS + 1; $n++) {
            $rows[] = ['source_record_id' => 'P'.$n, 'local_patient_ref' => 'P'.$n, 'opening_date' => '2000-01-01'];
        }
        foreach ([$rows, [$this->patient(['import_note' => str_repeat('W', 20001)])]] as $people) {
            $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])->post('/api/dossiers/imports', ['facility_id' => $this->f['facility'], 'purpose' => 'legacy_migration', 'cutover_date' => '2026-09-01', 'file' => $this->file(['Patients' => $people])])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('dossier_import_batches')->where('facility_id', $this->f['facility'])->count());
        $this->assertSame([], Storage::disk('dossier_private')->allFiles());
    }

    public function test_composite_foreign_keys_reject_cross_facility_provenance(): void
    {
        $b = $this->upload(['Patients' => [$this->patient()]]);
        $row = (array) DB::table('dossier_import_rows')->where('batch_id', $b['id'])->first();
        $rowId = $row['id'];
        unset($row['id']);
        $row['facility_id'] = $this->f['other'];
        $row['source_record_id'] .= '-wrong-scope';
        foreach ([['dossier_import_rows', $row], ['dossier_import_sources', ['facility_id' => $this->f['other'], 'sheet' => 'Patients', 'source_record_id' => 'cross-scope', 'fingerprint' => str_repeat('a', 64), 'row_id' => $rowId]]] as [$table, $values]) {
            try {
                DB::table($table)->insert($values);
                $this->fail('Composite facility foreign key must reject this insert.');
            } catch (QueryException $e) {
                $this->assertSame(1452, $e->errorInfo[1]);
            }
        }
        $this->assertSame(1, DB::table('dossier_import_rows')->where('batch_id', $b['id'])->count());
    }
}
