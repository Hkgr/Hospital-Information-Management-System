<?php

namespace Tests\Feature;

use App\Services\BloodBank\BloodBankReconcile;
use Database\Seeders\BloodBankReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BloodBankFixture;
use Tests\TestCase;

class BloodBankProfileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function eventInput(array $f, string $kind = 'donation'): array
    {
        return ['facility_id' => $f['facility'], 'request_id' => (string) Str::uuid(), 'kind' => $kind,
            ...($kind === 'benefit' ? ['benefit_kind' => 'issue'] : []),
            'person' => ['person_mode' => 'direct', 'first_name' => 'أحمد', 'family_name' => 'محمد', 'gender' => 'unknown', 'birth_date_accuracy' => 'unknown', 'displacement_status' => 'unknown'],
            'clinic_id' => $f['clinic'], 'responsible_staff_id' => $f['staff'], 'blood_component_id' => $f['component'],
            'occurred_on' => $f['today'], 'blood_group' => 'O', 'rh' => 'positive', 'quantity' => '0.4500', 'quantity_unit' => 'kg', 'screenings' => []];
    }

    public function test_profiles_allow_no_screenings_and_completion_without_result(): void
    {
        $f = BloodBankFixture::make();
        app(BloodBankReconcile::class)->apply();
        $this->withHeader('Authorization', 'Bearer '.$f['user']->createToken('workflow-test', ['api'])->plainTextToken);
        foreach (['donation', 'benefit'] as $kind) {
            $input = $this->eventInput($f, $kind);
            $input['screenings'] = [];
            $row = $this->postJson('/api/blood-bank/events', $input)->assertCreated()->assertJsonPath('data.screenings', [])->json('data');
            unset($input['person']);
            $input['person_id'] = $row['person_id'];
            $input['lock_version'] = 1;
            $input['request_id'] = (string) Str::uuid();
            $input['screenings'] = [['analyte' => 'HCV', 'status' => 'complete']];
            $this->putJson('/api/blood-bank/events/'.$row['id'], $input)->assertOk()->assertJsonCount(1, 'data.screenings')->assertJsonPath('data.screenings.0.result', null);
            $this->assertDatabaseHas('blood_bank_event_screenings', ['event_id' => $row['id'], 'analyte' => 'HCV', 'status' => 'complete', 'result' => null]);
        }
    }

    public function test_status_only_updates_preserve_historical_results_and_methods(): void
    {
        $f = BloodBankFixture::make();
        app(BloodBankReconcile::class)->apply();
        $this->withHeader('Authorization', 'Bearer '.$f['user']->createToken('workflow-test', ['api'])->plainTextToken);
        $input = $this->eventInput($f);
        $input['screenings'] = [['analyte' => 'HCV', 'status' => 'complete']];
        $row = $this->postJson('/api/blood-bank/events', $input)->assertCreated()->json('data');
        DB::table('blood_bank_event_screenings')->where('event_id', $row['id'])->where('analyte', 'HCV')->update(['status' => 'complete', 'result' => 'indeterminate', 'screening_test_id' => $f['test']]);
        unset($input['person']);
        $input['person_id'] = $row['person_id'];
        $input['lock_version'] = 1;
        $input['request_id'] = (string) Str::uuid();
        $input['screenings'] = [['analyte' => 'HCV', 'status' => 'pending']];
        $this->putJson('/api/blood-bank/events/'.$row['id'], $input)->assertOk();
        $this->assertDatabaseHas('blood_bank_event_screenings', ['event_id' => $row['id'], 'analyte' => 'HCV', 'status' => 'pending', 'result' => 'indeterminate', 'screening_test_id' => $f['test']]);
    }

    public function test_reference_completion_is_idempotent_and_keeps_existing_identities(): void
    {
        $governorate = DB::table('governorates')->insertGetId(['code' => 'OLD-ALEPPO', 'name_ar' => 'حلب']);
        $component = DB::table('blood_components')->insertGetId(['code' => 'OLD-WHOLE', 'name_ar' => 'دم كامل']);
        app(BloodBankReferenceSeeder::class)->run();
        $before = [];
        foreach (['governorates', 'cities', 'blood_components'] as $t) {
            $before[$t] = DB::table($t)->orderBy('id')->get()->toJson();
        }
        app(BloodBankReferenceSeeder::class)->run();
        foreach ($before as $t => $value) {
            $this->assertSame($value, DB::table($t)->orderBy('id')->get()->toJson());
        }
        $this->assertDatabaseHas('governorates', ['id' => $governorate, 'code' => 'OLD-ALEPPO', 'country_code' => 'SY']);
        $this->assertDatabaseHas('blood_components', ['id' => $component, 'code' => 'OLD-WHOLE', 'name_ar' => 'دم كامل', 'registration_kind' => 'whole']);
    }

    public function test_manual_addresses_round_trip_without_polluting_catalogs_and_mixed_paths_are_rejected(): void
    {
        $f = BloodBankFixture::make();
        app(BloodBankReconcile::class)->apply();
        $this->withHeader('Authorization', 'Bearer '.$f['user']->createToken('workflow', ['api'])->plainTextToken);
        $gov = DB::table('governorates')->where('code', 'SY-HL')->value('id');
        $city = DB::table('cities')->where('governorate_id', $gov)->value('id');
        $other = DB::table('governorates')->where('code', 'SY-HM')->value('id');
        $counts = [DB::table('governorates')->count(), DB::table('cities')->count()];
        foreach (['donation', 'benefit'] as $kind) {
            foreach ([['governorate_text' => 'محافظة خارجية', 'city_text' => 'مدينة خارجية'], ['governorate_id' => $gov, 'city_text' => 'مدينة غير مدرجة'], ['governorate_id' => $gov, 'city_id' => $city]] as $address) {
                $input = $this->eventInput($f, $kind);
                $input['person'] = $address + $input['person'];
                $row = $this->postJson('/api/blood-bank/events', $input)->assertCreated()->json('data');
                $read = $this->getJson('/api/blood-bank/people/'.$row['person_id'].'?facility_id='.$f['facility'])->assertOk()->json('data');
                foreach ($address as $key => $value) {
                    $this->assertEquals($value, $read['person'][$key]);
                }
                $this->assertDatabaseHas('blood_bank_people', ['id' => $row['person_id']] + $address);
            }
            foreach ([['governorate_id' => $other, 'city_id' => $city], ['governorate_id' => $gov, 'governorate_text' => 'خلط'], ['governorate_text' => 'خارجي', 'city_id' => $city], ['governorate_id' => $gov, 'city_id' => $city, 'city_text' => 'خلط'], ['city_text' => 'بلا محافظة']] as $address) {
                $input = $this->eventInput($f, $kind);
                $input['person'] = $address + $input['person'];
                $this->postJson('/api/blood-bank/events', $input)->assertUnprocessable();
            }
        }
        $this->assertSame($counts, [DB::table('governorates')->count(), DB::table('cities')->count()]);
        $options = $this->getJson('/api/blood-bank/options?facility_id='.$f['facility'])->assertOk()->json('data');
        $this->assertSame(['كامل', 'ركازة', 'بلازما', 'صفيحات'], array_column($options['blood_components'], 'name_ar'));
        $this->assertCount(14, $options['governorates']);
        foreach ($options['blood_components'] as $component) {
            $input = ['blood_component_id' => $component['id']] + $this->eventInput($f);
            $this->postJson('/api/blood-bank/events', $input)->assertCreated()->assertJsonPath('data.blood_component_id', $component['id']);
        }
    }

    public function test_optional_analytes_are_unique_and_omission_does_not_delete_saved_rows(): void
    {
        $f = BloodBankFixture::make();
        app(BloodBankReconcile::class)->apply();
        $this->withHeader('Authorization', 'Bearer '.$f['user']->createToken('workflow', ['api'])->plainTextToken);
        $input = $this->eventInput($f);
        $input['screenings'] = [['analyte' => 'HCV', 'status' => 'complete'], ['analyte' => 'HCV', 'status' => 'pending']];
        $this->postJson('/api/blood-bank/events', $input)->assertUnprocessable();
        $input['screenings'][1]['analyte'] = 'HIV';
        $row = $this->postJson('/api/blood-bank/events', $input)->assertCreated()->assertJsonCount(2, 'data.screenings')->json('data');
        unset($input['person'], $input['screenings']);
        $input['person_id'] = $row['person_id'];
        $input['lock_version'] = 1;
        $input['request_id'] = (string) Str::uuid();
        $this->putJson('/api/blood-bank/events/'.$row['id'], $input)->assertOk()->assertJsonCount(2, 'data.screenings');
    }

    public function test_doctor_options_count_unique_current_doctors_and_explain_unconfigured_directory(): void
    {
        $f = BloodBankFixture::make();
        app(BloodBankReconcile::class)->apply();
        $this->withHeader('Authorization', 'Bearer '.$f['user']->createToken('workflow', ['api'])->plainTextToken);
        for ($i = 1; $i <= 25; $i++) {
            DB::table('clinic_staff')->insert(['clinic_id' => $f['clinic'], 'staff_id' => $f['staff'], 'starts_on' => now()->subDays($i)->toDateString()]);
        }
        $url = '/api/blood-bank/doctors?facility_id='.$f['facility'].'&clinic_id='.$f['clinic'];
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $f['staff'])->assertJsonPath('meta.total', 1)->assertJsonPath('meta.last_page', 1);
        DB::table('staff')->where('id', $f['staff'])->update(['is_active' => false]);
        $this->getJson($url)->assertJsonPath('data', []);
        DB::table('staff')->where('id', $f['staff'])->update(['is_active' => true]);
        DB::table('clinic_staff')->where('clinic_id', $f['clinic'])->update(['ends_on' => $f['today']]);
        $this->getJson($url)->assertJsonPath('data', []);
        config(['clinics.doctor_staff_types' => []]);
        $this->getJson($url)->assertJsonPath('data', [])->assertJsonPath('doctor_types_configured', false);
    }
}
