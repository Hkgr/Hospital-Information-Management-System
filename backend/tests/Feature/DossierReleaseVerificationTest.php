<?php

namespace Tests\Feature;

use App\Services\BloodBank\BloodBankReports;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\Support\DossierCompletionCase;
use Tests\Support\DossierWorkbookAssertions;

class DossierReleaseVerificationTest extends DossierCompletionCase
{
    private function attachment(string $key, bool $voided = false): void
    {
        DB::table('visit_attachments')->insert(['facility_id' => $this->f['facility'], 'dossier_id' => $this->s['id'], 'visit_id' => $this->s['visit']['id'], 'title' => '=ملف اصطناعي', 'original_filename' => 'sample.pdf', 'storage_key' => $key, 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 32, 'sha256' => str_repeat('a', 64), 'client_request_id' => (string) Str::uuid(), 'entered_by' => $this->f['user']->id, 'created_at' => now(), 'updated_at' => now(), 'voided_at' => $voided ? now() : null, 'voided_by' => $voided ? $this->f['user']->id : null, 'void_reason' => $voided ? 'تصحيح تاريخي' : null]);
    }

    public function test_all_three_workbooks_round_trip_print_properties_types_and_literal_user_values(): void
    {
        DB::table('patient_dossiers')->where('id', $this->s['id'])->update(['code' => '0000123', 'clinical_history' => str_repeat('W', 800)."\nنص عربي مُشَكَّل 😀"]);
        DB::table('patients')->where('id', $this->s['patient']['id'])->update(['patient_code' => '0000456', 'first_name' => '=HYPERLINK("https://invalid")', 'family_name' => '+اختبار', 'father_name' => '-اسم أب', 'mother_name' => '@اسم أم', 'birth_date' => '1980-02-03', 'birth_date_accuracy' => 'exact', 'phone' => '0012345678']);
        $this->s = $this->saveSection('clinical', ['services' => [['catalog_id' => $this->f['service'], 'note' => '=1+1'] + $this->context()], 'procedures' => [['catalog_id' => $this->f['procedure'], 'note' => '+SUM(1,2)'] + $this->context()]])->assertOk()->json('data');
        $rx = $this->rx();
        $rx['note'] = '-وصفة';
        $rx['items'][0]['note'] = '@دواء';
        $this->s = $this->saveSection('medications', ['prescription' => $rx, 'outcome' => $this->outcome(['note' => '=نتيجة'])])->assertOk()->json('data');
        $privateKey = 'files/'.Str::uuid().'.pdf';
        $this->attachment($privateKey);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $request = Request::create('/api/dossiers/export/xlsx', 'POST');
        $request->setUserResolver(fn () => $this->f['user']);
        foreach (['list' => [null, null], 'dossier' => [$this->s['id'], null], 'visit' => [$this->s['id'], $this->s['visit']['id']]] as $kind => [$dossier, $visit]) {
            $doc = app(DossierReports::class)->document($request, $f, ['search' => '0000123'], $dossier, $visit);
            $path = tempnam(storage_path('framework/testing'), 'release-xlsx');
            file_put_contents($path, app(BloodBankReports::class)->xlsx($doc));
            try {
                $this->assertSame('Xlsx', IOFactory::identify($path));
                $book = IOFactory::load($path);
                DossierWorkbookAssertions::check($book, $kind === 'list');
                foreach ($doc['sections'] as $section) {
                    $sheet = $book->getSheetByName($section['title']);
                    $this->assertNotNull($sheet);
                    foreach ($section['rows'] as $index => $row) {
                        foreach (array_keys($section['labels']) as $col => $key) {
                            $cell = $sheet->getCell([$col + 1, $index + 9]);
                            $type = $row['_types'][$key] ?? $section['types'][$key] ?? null;
                            $value = $row[$key] ?? '';
                            if ($value !== '' && $value !== null && in_array($type, ['date', 'datetime', 'integer', 'decimal'])) {
                                $this->assertSame('n', $cell->getDataType(), "$kind $key");
                                $expected = in_array($type, ['date', 'datetime']) ? Date::PHPToExcel(new \DateTimeImmutable($value)) : (float) $value;
                                $this->assertEqualsWithDelta($expected, $cell->getValue(), 0.00000001, "$kind $key");
                            } else {
                                $this->assertSame((string) $value, (string) $cell->getValue(), "$kind $key");
                                if (is_string($value) && $value !== '') {
                                    $this->assertSame('s', $cell->getDataType(), "$kind $key");
                                }
                            }
                        }
                    }
                }
                $book->disconnectWorksheets();
                $zip = new \ZipArchive;
                $this->assertTrue($zip->open($path));
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    $this->assertStringNotContainsString('embeddings/', $entry);
                    $this->assertStringNotContainsString($privateKey, $zip->getFromIndex($i));
                    if (str_starts_with($entry, 'xl/media/')) {
                        $this->assertSame(file_get_contents(resource_path('reports/logo-ar-color.png')), $zip->getFromIndex($i), 'Only the hospital identity image may be embedded');
                    }
                }
                $zip->close();
            } finally {
                unlink($path);
            }
        }
    }

    public function test_cleanup_dry_run_apply_age_and_recorded_including_voided_history(): void
    {
        Storage::fake('dossier_private');
        $disk = Storage::disk('dossier_private');
        $old = ['staging/'.Str::uuid(), 'files/'.Str::uuid().'.pdf'];
        $recorded = ['files/'.Str::uuid().'.pdf', 'files/'.Str::uuid().'.pdf'];
        foreach ($recorded as $i => $key) {
            $this->attachment($key, $i === 1);
        }
        $this->freezeTime();
        $recent = 'staging/'.Str::uuid();
        $boundary = 'files/'.Str::uuid().'.pdf';
        $unmanaged = ['files/operator-note.pdf', 'files/'.str_repeat('-', 36).'.pdf', 'other/'.Str::uuid().'.pdf'];
        $all = [...$old, ...$recorded, $recent, $boundary, ...$unmanaged];
        foreach ($all as $key) {
            $disk->put($key, 'synthetic bytes');
            touch($disk->path($key), now()->subHours(25)->getTimestamp());
        }
        touch($disk->path($recent), now()->subHours(23)->getTimestamp());
        touch($disk->path($boundary), now()->subHours(24)->getTimestamp());
        $before = DB::table('visit_attachments')->orderBy('id')->get()->toJson();
        $this->artisan('dossiers:cleanup-uploads')->expectsOutput('Would remove 2 abandoned upload files. Recorded attachments were retained.')->assertSuccessful();
        $disk->assertExists($all);
        $this->artisan('dossiers:cleanup-uploads', ['--apply' => true])->expectsOutput('Removed 2 abandoned upload files. Recorded attachments were retained.')->assertSuccessful();
        $disk->assertMissing($old);
        $disk->assertExists([...$recorded, $recent, $boundary, ...$unmanaged]);
        $this->assertSame($before, DB::table('visit_attachments')->orderBy('id')->get()->toJson());
    }

    public function test_private_disk_cannot_be_served_or_linked_into_public_storage(): void
    {
        $config = require config_path('filesystems.php');
        $this->assertFalse($config['disks']['dossier_private']['serve']);
        $this->assertStringStartsWith(storage_path('app'), $config['disks']['dossier_private']['root']);
        $this->assertStringNotContainsString(public_path(), $config['disks']['dossier_private']['root']);
        $this->assertNotContains($config['disks']['dossier_private']['root'], array_values($config['links']));
        foreach (['/storage/dossier-private/files/test.pdf', '/dossier-private/files/test.pdf'] as $path) {
            $response = $this->get($path);
            $this->assertContains($response->status(), [403, 404]);
        }
    }
}
