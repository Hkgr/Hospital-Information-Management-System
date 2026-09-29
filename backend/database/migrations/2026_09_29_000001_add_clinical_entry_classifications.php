<?php

use App\Services\Directory\IssuedCodes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $t) {
            $t->string('national_id', 20)->nullable()->unique('unique_patients_national_id');
        });
        Schema::table('clinics', function (Blueprint $t) {
            $t->string('care_setting', 20)->nullable();
            $t->string('inpatient_kind', 20)->nullable();
        });
        Schema::table('staff', function (Blueprint $t) {
            $t->string('practice_group', 20)->nullable();
        });
        Schema::table('visit_services', function (Blueprint $t) {
            $t->string('manual_doctor_name', 200)->nullable();
        });
        Schema::table('visit_procedures', function (Blueprint $t) {
            $t->string('manual_doctor_name', 200)->nullable();
        });
        DB::statement('ALTER TABLE visit_procedures MODIFY specialist_id BIGINT UNSIGNED NULL');
        Schema::table('blood_bank_events', function (Blueprint $t) {
            $t->decimal('hemoglobin_g_dl', 4, 1)->nullable();
            $t->string('crossmatch_result', 20)->nullable();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE clinics ADD CONSTRAINT ck_clinics_care_setting CHECK (care_setting IS NULL OR care_setting IN ('outpatient','inpatient','surgical'))");
            DB::statement("ALTER TABLE clinics ADD CONSTRAINT ck_clinics_inpatient_kind CHECK ((care_setting = 'inpatient' AND inpatient_kind IN ('blood','oncology','thalassemia','surgical')) OR (IFNULL(care_setting, '') <> 'inpatient' AND inpatient_kind IS NULL))");
            DB::statement("ALTER TABLE staff ADD CONSTRAINT ck_staff_practice_group CHECK (practice_group IS NULL OR practice_group IN ('resident','specialist'))");
            DB::statement('ALTER TABLE visit_services ADD CONSTRAINT ck_visit_services_manual_doctor CHECK (manual_doctor_name IS NULL OR performed_by IS NULL)');
            DB::statement('ALTER TABLE visit_procedures ADD CONSTRAINT ck_visit_procedures_manual_doctor CHECK (manual_doctor_name IS NULL OR specialist_id IS NULL)');
            DB::statement("ALTER TABLE blood_bank_events ADD CONSTRAINT ck_blood_events_crossmatch CHECK (crossmatch_result IS NULL OR crossmatch_result IN ('compatible','incompatible'))");
        }

        DB::transaction(function () {
            $codes = app(IssuedCodes::class);
            foreach (['استشارة جراحية', 'بزل', 'خزعة نقي العظم', 'خزعة موجهة بالإيكو', 'تغيير ضماد', 'لطاخة', 'خزعات نسجية'] as $name) {
                if (! DB::table('procedures')->where('name_ar', $name)->exists()) {
                    DB::table('procedures')->insert(['code' => $codes->catalog('procedure'), 'name_ar' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            foreach (['whole' => 'دم كامل', 'red_cells' => 'كريات مكثفة', 'plasma' => 'بلازما', 'platelets' => 'صفيحات'] as $kind => $name) {
                DB::table('blood_components')->where('registration_kind', $kind)->update(['name_ar' => $name, 'updated_at' => now()]);
            }
            $this->doctors();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            foreach ([
                'clinics' => ['ck_clinics_care_setting', 'ck_clinics_inpatient_kind'],
                'staff' => ['ck_staff_practice_group'],
                'visit_services' => ['ck_visit_services_manual_doctor'],
                'visit_procedures' => ['ck_visit_procedures_manual_doctor'],
                'blood_bank_events' => ['ck_blood_events_crossmatch'],
            ] as $table => $constraints) {
                foreach ($constraints as $name) {
                    DB::statement("ALTER TABLE `$table` DROP CONSTRAINT `$name`");
                }
            }
        }
        DB::statement('ALTER TABLE visit_procedures MODIFY specialist_id BIGINT UNSIGNED NOT NULL');
        Schema::table('blood_bank_events', fn (Blueprint $t) => $t->dropColumn(['hemoglobin_g_dl', 'crossmatch_result']));
        Schema::table('visit_procedures', fn (Blueprint $t) => $t->dropColumn('manual_doctor_name'));
        Schema::table('visit_services', fn (Blueprint $t) => $t->dropColumn('manual_doctor_name'));
        Schema::table('staff', fn (Blueprint $t) => $t->dropColumn('practice_group'));
        Schema::table('clinics', fn (Blueprint $t) => $t->dropColumn(['care_setting', 'inpatient_kind']));
        Schema::table('patients', fn (Blueprint $t) => $t->dropUnique('unique_patients_national_id')->dropColumn('national_id'));
    }

    private function doctors(): void
    {
        $groups = [
            'resident' => ['محمود مقرش', 'سدرة كوردي', 'بتول الحكيم', 'أديل خاجو', 'سليمان سروخان', 'زين دوبا', 'سمية طبشو', 'إيمان المحمد', 'سوزان محفوض', 'سدرة حياني', 'محمد سليم', 'رؤى شيط', 'نجوى ناصر', 'عبد الله طه', 'راما البر', 'هبة حاج صالح'],
            'specialist' => ['محمد مواس', 'هماء المحمد', 'ياسمين قنينة', 'زينة زكور', 'ريما صناع', 'أوراما كورية', 'أحمد العيسى', 'إياد العريان', 'روعة سرميني', 'سامر نسطة', 'عدنان عكش'],
        ];
        $normalize = function (string $name): string {
            $name = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $name);

            return trim((string) preg_replace('/\s+/u', ' ', $name));
        };
        $existing = DB::table('staff')->get(['id', 'full_name']);
        $codes = array_values(array_filter(config('clinics.doctor_staff_types', []), fn ($code) => $code !== 'NURSE'));
        if (! $codes) {
            $codes = config('clinics.doctor_staff_types', []);
        }
        $typeId = $codes
            ? DB::table('staff_types')->whereIn('code', $codes)->where('is_active', true)->orderBy('id')->value('id')
            : DB::table('staff')->whereNotNull('staff_type_id')->orderBy('id')->value('staff_type_id');
        foreach ($groups as $group => $names) {
            foreach ($names as $name) {
                $key = $normalize($name);
                $match = $existing->first(fn ($row) => $normalize((string) $row->full_name) === $key);
                if ($match) {
                    DB::table('staff')->where('id', $match->id)->update(['practice_group' => $group, 'updated_at' => now()]);

                    continue;
                }
                if (! $typeId) {
                    continue;
                }
                $id = DB::table('staff')->insertGetId([
                    'staff_code' => app(IssuedCodes::class)->doctor(),
                    'full_name' => $name,
                    'search_name' => $name,
                    'staff_type_id' => $typeId,
                    'practice_group' => $group,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $existing->push((object) ['id' => $id, 'full_name' => $name]);
            }
        }
    }
};
