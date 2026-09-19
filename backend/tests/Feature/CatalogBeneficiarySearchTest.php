<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogFixture;
use Tests\TestCase;

class CatalogBeneficiarySearchTest extends TestCase
{
    use RefreshDatabase;

    public static function kinds(): array
    {
        return [['service'], ['procedure'], ['medication']];
    }

    #[DataProvider('kinds')]
    public function test_full_name_search_preserves_literal_characters_events_and_paginated_totals(string $kind): void
    {
        $f = CatalogFixture::make();
        $token = CatalogFixture::token($f['user'], 'search-regression');
        $id = $f['items'][$kind][2];
        $table = 'visit_'.$kind.'s';
        $event = (array) DB::table($table)->where($kind.'_id', $id)->first();
        unset($event['id']);
        $duplicate = $event;
        $duplicate['client_request_id'] = (string) Str::uuid();
        DB::table($table)->insert($duplicate);
        $other = $event;
        $other['visit_id'] = DB::table('visits')->where('patient_id', $f['patients'][2])->value('id');
        $other['client_request_id'] = (string) Str::uuid();
        DB::table($table)->insert($other);
        DB::table('patients')->where('id', $f['patients'][1])->update(['first_name' => 'أحمد', 'family_name' => 'محمد']);

        $search = function (string $term, int $count = 2, int $patients = 1, array $filters = []) use ($f, $token, $kind, $id) {
            $this->app['auth']->forgetGuards();

            return $this->json('GET', "/api/service-catalog/$kind/$id/events", $filters + ['facility_id' => $f['facility'], 'search' => $term], ['Authorization' => 'Bearer '.$token])
                ->assertOk()->assertJsonPath('totals.presentations', $count)->assertJsonPath('totals.unique_patients', $patients)->assertJsonPath('meta.total', $count);
        };

        // Regression: this is the exact name displayed by the presentation table.
        $rows = $search('أحمد محمد')->json('data');
        $this->assertCount(2, $rows);
        $this->assertCount(2, array_unique(array_column($rows, 'key')));
        $this->assertSame(['أحمد محمد'], array_values(array_unique(array_column($rows, 'patient_name'))));
        foreach (['أحمد', 'محمد', '  أحمد محمد  ', 'أحمد   محمد', "\tأحمد\t  محمد\n", "أحمد\u{00A0}محمد", $f['tag'].'-P1'] as $term) {
            $search($term);
        }
        foreach (['لا يوجد هذا الاسم', '%', '_', "' OR 1=1 --"] as $term) {
            $search($term, 0, 0)->assertJsonPath('data', []);
        }
        $search('   ', 3, 2);

        // Whitespace in stored multiword names must not prevent finding the displayed name either.
        DB::table('patients')->where('id', $f['patients'][1])->update(['first_name' => 'أحمد  عبد', 'family_name' => '  محمد ']);
        $search('  أحمد عبد   محمد  ');
        DB::table('patients')->where('id', $f['patients'][1])->update(['first_name' => 'أحمد%', 'family_name' => 'محمد_', 'patient_code' => 'PAT_%\\X']);
        foreach (['%', '_', '\\', 'أحمد% محمد_', 'PAT_%\\X'] as $term) {
            $search($term);
        }
        $search('PATXX', 0, 0);
        DB::table('patients')->where('id', $f['patients'][1])->update(['first_name' => 'أحمد', 'family_name' => 'محمد']);
        for ($n = 0; $n < 12; $n++) {
            $event['client_request_id'] = (string) Str::uuid();
            DB::table($table)->insert($event);
        }
        $first = $search('أحمد محمد', 14, 1, ['per_page' => 10])->json('data');
        $second = $search('أحمد  محمد', 14, 1, ['per_page' => 10, 'page' => 2])->json('data');
        $this->assertCount(10, $first);
        $this->assertCount(4, $second);
        $this->assertCount(14, array_unique(array_column([...$first, ...$second], 'key')));
        $search('أحمد محمد', 0, 0, ['to' => now('Asia/Damascus')->subDay()->toDateString()]);
    }
}
