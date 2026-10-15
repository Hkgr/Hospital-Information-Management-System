<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DossierCompletionCase;

class PrescriptionMedicationSourcesTest extends DossierCompletionCase
{
    public function test_general_and_prescription_choices_are_the_same_live_reference(): void
    {
        $id = DB::table('funding_sources')->insertGetId(['code' => 'CUSTOM-'.$this->f['tag'], 'name_ar' => 'مصدر مشترك جديد', 'is_active' => true]);
        $general = $this->callApi('GET', '/options')->assertOk()->json('data.medication_sources');
        $funding = $this->callApi('GET', '/prescription-funding')->assertOk()->json('data.funding_sources');
        $this->assertSame($general, $funding);
        $this->assertContains($id, array_column($general, 'id'));
        DB::table('funding_sources')->where('id', $id)->update(['name_ar' => 'اسم معدل']);
        $source = DB::table('funding_sources')->where('id', $id)->first();
        $this->s = $this->callApi('PUT', '/'.$this->s['id'].'/medical', ['lock_version' => $this->s['lock_version'], 'is_oncology' => true, 'medication_source' => $source->code])->assertOk()->json('data');
        $this->assertSame($id, $this->s['medical']['medication_source_id']);
        $this->assertSame('اسم معدل', $this->s['medical']['medication_source_name']);
        DB::table('funding_sources')->where('id', $id)->update(['is_active' => false]);
        $this->assertNotContains($id, array_column($this->callApi('GET', '/options')->assertOk()->json('data.medication_sources'), 'id'));
        $this->assertSame($this->callApi('GET', '/options')->json('data.medication_sources'), $this->callApi('GET', '/prescription-funding')->json('data.funding_sources'));
        $this->callApi('GET', '/'.$this->s['id'].'/progress')->assertOk()->assertJsonPath('data.medical.medication_source_name', 'اسم معدل');
    }

    public function test_every_kind_requires_an_explicit_valid_source_and_preserves_other_prescriptions(): void
    {
        $disabled = DB::table('funding_sources')->insertGetId(['code' => 'OFF-'.$this->f['tag'], 'name_ar' => 'معطل', 'is_active' => false]);
        $ids = [];
        foreach (['unlinked', 'dose_linked', 'outside'] as $kind) {
            $rx = $this->rx() + ['kind' => $kind];
            if ($kind === 'outside') {
                $rx['unavailable_reason'] = 'غير متوفر';
            }
            foreach ([null, 999999999, $disabled] as $bad) {
                $before = DB::table('visit_prescriptions')->count();
                $this->saveSection('medications', ['prescription' => array_replace($rx, ['funding_source_id' => $bad]), 'outcome' => null])->assertUnprocessable()->assertJsonValidationErrors('prescription.funding_source_id');
                $this->assertSame($before, DB::table('visit_prescriptions')->count());
            }
            $this->s = $this->saveSection('medications', ['prescription' => $rx, 'outcome' => null])->assertOk()->json('data');
            $saved = collect($this->s['clinical']['prescriptions'])->firstWhere('kind', $kind);
            $this->assertSame($rx['funding_source_id'], $saved['funding_source_id']);
            $ids[] = $saved['id'];
        }
        $this->assertSame($ids, array_column($this->s['clinical']['prescriptions'], 'id'));
        $this->callApi('GET', '/'.$this->s['id'].'/progress')->assertOk()->assertJsonCount(3, 'data.clinical.prescriptions');
        $this->callApi('GET', '/prescription-funding', ['facility_id' => $this->f['other']])->assertForbidden();
    }

    public function test_legacy_missing_and_disabled_sources_remain_readable_and_correction_is_explicit(): void
    {
        $rx = $this->rx();
        $this->s = $this->saveSection('medications', ['prescription' => $rx, 'outcome' => null])->assertOk()->json('data');
        $p = $this->s['clinical']['prescriptions'][0];
        DB::table('visit_prescriptions')->where('id', $p['id'])->update(['funding_source_id' => null]);
        $this->callApi('GET', '/'.$this->s['id'].'/progress')->assertOk()->assertJsonPath('data.clinical.prescriptions.0.funding_source_id', null);
        $response = $this->callApi('POST', '/'.$this->s['id'].'/report/xlsx')->assertOk();
        $file = tempnam(storage_path('framework/testing'), 'source-report-');
        file_put_contents($file, $response->getContent());
        try {
            $book = IOFactory::load($file);
            $text = '';
            foreach ($book->getAllSheets() as $sheet) {
                foreach ($sheet->toArray() as $values) {
                    $text .= implode(' ', array_map('strval', $values))."\n";
                }
            }
            $this->assertStringContainsString('مصدر تمويل الوصفة: غير محدد', $text);
            $this->assertStringNotContainsString('فاتورة', $text);
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
        $pdf = $this->callApi('POST', '/'.$this->s['id'].'/report/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $edit = $rx + ['id' => $p['id'], 'lock_version' => $p['lock_version']];
        $this->saveSection('medications', ['prescription' => array_replace($edit, ['funding_source_id' => null]), 'outcome' => null])->assertUnprocessable();
        $this->s = $this->saveSection('medications', ['prescription' => $edit, 'outcome' => null])->assertOk()->json('data');
        $current = $this->s['clinical']['prescriptions'][0];
        $other = DB::table('funding_sources')->insertGetId(['code' => 'CHANGE-'.$this->f['tag'], 'name_ar' => 'مصدر بديل', 'is_active' => true]);
        $this->s = $this->saveSection('medications', ['prescription' => array_replace($edit, ['lock_version' => $current['lock_version'], 'funding_source_id' => $other]), 'outcome' => null])->assertOk()->json('data');
        DB::table('funding_sources')->where('id', $other)->update(['is_active' => false]);
        $current = $this->s['clinical']['prescriptions'][0];
        $this->assertSame('مصدر بديل', $current['funding_name']);
        $this->s = $this->saveSection('medications', ['prescription' => array_replace($edit, ['lock_version' => $current['lock_version'], 'funding_source_id' => $other, 'note' => 'تصحيح ملاحظة']), 'outcome' => null])->assertOk()->json('data');
        $this->assertSame($other, $this->s['clinical']['prescriptions'][0]['funding_source_id']);
        $this->saveSection('medications', ['prescription' => array_replace($rx, ['funding_source_id' => $other]), 'outcome' => null])->assertUnprocessable();
    }
}
