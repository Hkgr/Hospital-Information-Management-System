<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds only the reference data the hospital's import file needs and the
 * current directory lacks. Never deletes or renames anything. Existing rows
 * are identified by code; every new row uses a code that does not collide.
 *
 *   php artisan db:seed --class=MissingReferenceSeeder --force
 *
 * Idempotent: running it twice changes nothing.
 */
class MissingReferenceSeeder extends Seeder
{
    // New people only. DR-012..016 already exist with different people, so
    // these take fresh codes; the import maps by name, not by code.
    private const NEW_DOCTORS = [
        'DR-017' => 'روعة سرميني',
        'DR-018' => 'شريف جزار',
        'DR-019' => 'أسامة فلاحة',
        'DR-020' => 'محمود عرفة',
    ];

    private const NEW_CLINICS = [
        'CLI-012' => 'المخبر',
        'CLI-013' => 'عيادة تلاسيميا',
    ];

    // clinic code => doctor codes that must be linked.
    private const LINKS = [
        'CLI-001' => ['DR-005', 'DR-006', 'DR-007', 'DR-008', 'DR-009', 'DR-010', 'DR-011'],
        'CLI-002' => ['DR-003', 'DR-005'],
        'CLI-012' => ['DR-018', 'DR-019', 'DR-020'],
        'CLI-013' => ['DR-017'],
    ];

    // Legacy records date back to 2020. Links must cover those dates.
    private const LINKS_START = '2000-01-01';

    private const NEW_DIAGNOSES = [
        'DOS-DX-17' => 'متابعة عمل جراحي \\مراقبة',
        'DOS-DX-18' => 'كتل الثدي الغير محددة',
        'DOS-DX-19' => 'كتل الابط الغير محددة',
        'DOS-DX-20' => 'تضخم العقد اللمفاوية',
        'DOS-DX-21' => 'كتل أخرى سليمة',
        'DOS-DX-22' => 'استشارة جراحية',
        'DOS-DX-23' => 'كتل الرحم السليمة',
        'DOS-DX-24' => 'كتل الرحم الخبيثة',
        'DOS-DX-25' => 'كتل الثدي الخبيثة',
        'DOS-DX-26' => 'كتل الثدي السليمة',
        'DOS-DX-27' => 'كتل الإبط الخبيثة',
        'DOS-DX-28' => 'كتل العنق الخبيثة',
        'DOS-DX-29' => 'كتل أخرى خبيثة',
        'DOS-DX-30' => 'المراقبة والمسح الروتيني بدون وجود مرض',
        'DOS-DX-31' => 'تلاسيميا كبرى',
        'DOS-DX-32' => 'تلاسيميا وسطى',
        'DOS-DX-33' => 'منجلي',
        'DOS-DX-34' => 'فقر دم لا مصنع',
        'DOS-DX-35' => 'تكور',
        'DOS-DX-36' => 'ورم الثدي',
        'DOS-DX-37' => 'ورم بنكرياس',
        'DOS-DX-38' => 'ورم معدة',
        'DOS-DX-39' => 'ورم الرئة',
        'DOS-DX-40' => 'ورم البروستات',
        'DOS-DX-41' => 'ورم الخصى',
        'DOS-DX-42' => 'ورم المريء',
        'DOS-DX-43' => 'ورم الجلد scc',
        'DOS-DX-44' => 'ورم الجلد Bcc',
        'DOS-DX-45' => 'ميلانوما',
        'DOS-DX-46' => 'ساركوما العظام و العضلات',
        'DOS-DX-47' => 'ورم كلية',
        'DOS-DX-48' => 'ورم رحم',
        'DOS-DX-49' => 'ورم مبيض',
        'DOS-DX-50' => 'ورم مستقيم',
        'DOS-DX-51' => 'ورم قولون',
        'DOS-DX-52' => 'ورم مثانة',
        'DOS-DX-53' => 'ورم دماغ',
        'DOS-DX-54' => 'ورم الغدة الدرقية',
        'DOS-DX-55' => 'ورم الكبد',
        'DOS-DX-56' => 'ورم عنق الرحم',
        'DOS-DX-57' => 'ورم طرق صفراوية',
        'DOS-DX-58' => 'ورم مرارة',
        'DOS-DX-59' => 'ورم المشيمائية',
        'DOS-DX-60' => 'ورم الغدة النكفية',
        'DOS-DX-61' => 'فقرالدم',
        'DOS-DX-62' => 'ورم دم نقوي حاد AML',
        'DOS-DX-63' => 'ورم دم لمفاوي حاد ALL',
        'DOS-DX-64' => 'ورم  دم نقوي مزمن CML',
        'DOS-DX-65' => 'ورم دم لمفاوي مزمن CLL',
        'DOS-DX-66' => 'عسر تصنع نقي MDS',
        'DOS-DX-67' => 'تليف نقي',
        'DOS-DX-68' => 'كثرة حمرة /احمرارالدم',
        'DOS-DX-69' => 'كثرة صفيحات',
        'DOS-DX-70' => 'ناعور',
        'DOS-DX-71' => 'نقيوم متعدد M.M',
        'DOS-DX-72' => 'لمفوما هودجكن',
        'DOS-DX-73' => 'لمفوما غير هودجكن',
        'DOS-DX-74' => 'نقص صفيحات ITP/TTP',
        'DOS-DX-75' => 'تطاول زمن النزف /إضرابات تخثر',
        'DOS-DX-76' => 'ورم الأمعاء الدقيقة',
        'DOS-DX-77' => 'خباثات أخرى',
        'DOS-DX-78' => 'ابيضاض وحيدي نقوي مزمن CMML',
        'DOS-DX-79' => 'لمفوما بوركت',
        'DOS-DX-80' => 'اضطرابات الدم و الأعضاء المكونة له',
        'DOS-DX-81' => 'خباثات الجهاز العصبي',
        'DOS-DX-82' => 'خباثات العين و ملحقاتها',
        'DOS-DX-83' => 'ورم الأمعاء الغليظة',
        'DOS-DX-84' => 'ورم غدي عصبي',
        'DOS-DX-85' => 'ورم قناة فالوب',
        'DOS-DX-86' => 'خباثات الجهاز البولي',
        'DOS-DX-87' => 'ورم ويلمز',
        'DOS-DX-88' => 'خباثات الرأس و العنق',
        'DOS-DX-89' => 'خباثات البلعوم الانفي',
        'DOS-DX-90' => 'خباثات البلعوم الفموي',
        'DOS-DX-91' => 'خباثات الفم',
        'DOS-DX-92' => 'خباثات اللسان',
        'DOS-DX-93' => 'خباثات الفك',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $now = now();
            $facility = DB::table('facilities')->where('code', 'MBZ-ALEPPO')->value('id')
                ?? throw new RuntimeException('Facility MBZ-ALEPPO not found.');
            $doctorType = DB::table('staff')->where('staff_code', 'DR-006')->value('staff_type_id')
                ?? throw new RuntimeException('Reference doctor DR-006 not found.');

            foreach (self::NEW_DOCTORS as $code => $name) {
                DB::table('staff')->insertOrIgnore(['staff_code' => $code, 'full_name' => $name, 'search_name' => $name, 'staff_type_id' => $doctorType, 'is_active' => true, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (self::NEW_CLINICS as $code => $name) {
                DB::table('clinics')->insertOrIgnore(['facility_id' => $facility, 'code' => $code, 'name_ar' => $name, 'is_active' => true, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }

            $staff = DB::table('staff')->pluck('id', 'staff_code');
            $clinics = DB::table('clinics')->where('facility_id', $facility)->pluck('id', 'code');

            // Backdate every existing open link that is the only period for its pair.
            foreach (DB::table('clinic_staff')->whereNull('ends_on')->where('starts_on', '>', self::LINKS_START)->get() as $link) {
                $periods = DB::table('clinic_staff')->where('clinic_id', $link->clinic_id)->where('staff_id', $link->staff_id)->count();
                if ($periods === 1) {
                    DB::table('clinic_staff')->where('id', $link->id)->update(['starts_on' => self::LINKS_START, 'updated_at' => $now]);
                }
            }
            foreach (self::LINKS as $clinic => $doctors) {
                foreach ($doctors as $doctor) {
                    $key = ['clinic_id' => $clinics[$clinic] ?? throw new RuntimeException("Clinic $clinic missing."),
                            'staff_id' => $staff[$doctor] ?? throw new RuntimeException("Doctor $doctor missing.")];
                    if (! DB::table('clinic_staff')->where($key)->exists()) {
                        DB::table('clinic_staff')->insert($key + ['starts_on' => self::LINKS_START, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }

            // Services: reuse the echo category from an existing echo service.
            $echoCategory = DB::table('services')->where('code', 'SER-2-001')->value('category_id')
                ?? throw new RuntimeException('Reference service SER-2-001 not found.');
            foreach (['SC-BLOOD' => 'دم', 'SC-CONSULT' => 'معاينة'] as $code => $name) {
                DB::table('service_categories')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            $cat = DB::table('service_categories')->pluck('id', 'code');
            $services = [
                ['SER-2-007', 'ايكو', $echoCategory, true],
                ['SER-4-001', 'نقل دم', $cat['SC-BLOOD'], false],
                ['SER-4-002', 'تسريب حديد', $cat['SC-BLOOD'], false],
                ['SER-5-001', 'معاينة / استشارة عيادة', $cat['SC-CONSULT'], false],
            ];
            foreach ($services as [$code, $name, $category, $external]) {
                DB::table('services')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'category_id' => $category, 'allow_external' => $external, 'is_active' => true, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }

            // The hospital records consultation and examination as one procedure.
            $procType = DB::table('procedures')->where('code', 'PRO-006')->value('procedure_type_id');
            DB::table('procedures')->insertOrIgnore(['code' => 'PRO-008', 'name_ar' => 'معاينة / استشارة', 'procedure_type_id' => $procType, 'is_active' => true, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);

            foreach (self::NEW_DIAGNOSES as $code => $name) {
                DB::table('diagnoses')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }, 3);
    }
}
