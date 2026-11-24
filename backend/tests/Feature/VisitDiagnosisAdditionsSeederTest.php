<?php

namespace Tests\Feature;

use Database\Seeders\VisitDiagnosisAdditionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisitDiagnosisAdditionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_only_missing_exact_trimmed_names_using_existing_code_issuer_and_is_repeatable(): void
    {
        $existing = DB::table('diagnoses')->get();
        $glanzmann = $existing->first(fn ($row) => trim($row->name_ar) === 'متلازمة غلانزمان');
        $id = $glanzmann?->id ?? DB::table('diagnoses')->insertGetId(['code' => 'TEST-GLANZ-'.Str::random(8), 'name_ar' => 'متلازمة غلانزمان']);
        DB::table('diagnoses')->where('id', $id)->update(['name_ar' => " \tمتلازمة غلانزمان\n "]);
        DB::table('diagnoses')->insert(['code' => 'TEST-PANCREAS-'.Str::random(8), 'name_ar' => 'ورم بنكرياس']);
        $before = DB::table('diagnoses')->get()->keyBy('id');
        $missing = array_diff(VisitDiagnosisAdditionsSeeder::LABELS, $before->pluck('name_ar')->map(fn ($name) => trim($name))->all());
        $this->seed(VisitDiagnosisAdditionsSeeder::class);
        $this->assertSame($before->count() + count($missing), DB::table('diagnoses')->count());
        foreach ($before as $row) {
            $this->assertSame((array) $row, (array) DB::table('diagnoses')->find($row->id));
        }
        $added = DB::table('diagnoses')->whereNotIn('id', $before->keys())->get();
        foreach ($added as $row) {
            $this->assertStringStartsWith('AUTO-DOS-DX-', $row->code);
            $this->assertNull($row->icd10_code);
            $this->assertNull($row->category_id);
            $this->assertSame(1, $row->is_active);
        }
        $all = DB::table('diagnoses')->get();
        foreach (VisitDiagnosisAdditionsSeeder::LABELS as $name) {
            $this->assertSame(1, $all->filter(fn ($row) => trim($row->name_ar) === $name)->count(), $name);
        }
        $this->assertDatabaseHas('diagnoses', ['name_ar' => 'ورم رأس البنكرياس']);
        $saved = DB::table('diagnoses')->orderBy('id')->get()->toJson();
        $sequence = DB::table('number_sequences')->where('sequence_key', 'diagnosis_code')->value('current_value');
        $this->seed(VisitDiagnosisAdditionsSeeder::class);
        $this->assertSame($saved, DB::table('diagnoses')->orderBy('id')->get()->toJson());
        $this->assertSame($sequence, DB::table('number_sequences')->where('sequence_key', 'diagnosis_code')->value('current_value'));
    }

    public function test_existing_inactive_definition_is_not_replaced_or_reactivated(): void
    {
        $id = DB::table('diagnoses')->where('name_ar', 'فون ويلبراند')->value('id')
            ?? DB::table('diagnoses')->insertGetId(['code' => 'TEST-VW-'.Str::random(8), 'name_ar' => 'فون ويلبراند']);
        DB::table('diagnoses')->where('id', $id)->update(['is_active' => false]);
        $before = (array) DB::table('diagnoses')->find($id);
        $this->seed(VisitDiagnosisAdditionsSeeder::class);
        $this->assertSame($before, (array) DB::table('diagnoses')->find($id));
        $this->assertSame(1, DB::table('diagnoses')->where('name_ar', 'فون ويلبراند')->count());
    }
}
