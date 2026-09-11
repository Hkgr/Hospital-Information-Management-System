<?php

// Explicit, isolated test-only artifact generation. All database fixtures roll back.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);

use App\Models\User;
use App\Services\Clinics\ClinicReports;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

config(['clinics.doctor_staff_types' => ['SAMPLE_DOCTOR']]);
DB::beginTransaction();
try {
    $suffix = Str::random(8);
    $user = User::factory()->create(['username' => 'sample-'.$suffix, 'name' => 'مستخدم التقارير الاختبارية']);
    $facilityId = DB::table('facilities')->insertGetId(['code' => 'SAMPLE-'.$suffix, 'name_ar' => 'مشفى محمد بن زايد الإماراتي — بيانات اختبارية', 'timezone' => 'Asia/Damascus']);
    $facility = ['id' => $facilityId, 'name_ar' => 'مشفى محمد بن زايد الإماراتي — بيانات اختبارية', 'timezone' => 'Asia/Damascus'];
    $type = DB::table('staff_types')->where('code', 'SAMPLE_DOCTOR')->value('id') ?? DB::table('staff_types')->insertGetId(['code' => 'SAMPLE_DOCTOR', 'name_ar' => 'طبيب اختبار']);
    $doctorIds = [];
    foreach (['أحمد الاختباري', 'ليلى التجريبية', 'سامر النموذجي'] as $index => $name) {
        $doctorIds[] = DB::table('staff')->insertGetId(['staff_code' => 'SAMPLE-'.$suffix.'-'.$index, 'full_name' => $name, 'search_name' => $name, 'staff_type_id' => $type]);
    }
    $clinicIds = [];
    $names = ['العيادة الداخلية', 'عيادة الأطفال', 'العيادة القلبية', 'عيادة الجراحة العامة'];
    for ($index = 0; $index < 65; $index++) {
        $id = DB::table('clinics')->insertGetId(['facility_id' => $facilityId, 'code' => str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT), 'name_ar' => $names[$index % 4].' — اختبار '.($index + 1), 'description' => 'توصيف عربي اختباري للرعاية والمتابعة الطبية؛ جميع البيانات في هذا التقرير اصطناعية.', 'is_active' => true]);
        $clinicIds[] = $id;
        foreach ($doctorIds as $doctorId) {
            DB::table('clinic_staff')->insert(['clinic_id' => $id, 'staff_id' => $doctorId, 'starts_on' => now('Asia/Damascus')->subMonth()->toDateString()]);
        }
    }
    $request = Request::create('/api/clinics/export/pdf');
    $request->setUserResolver(fn () => $user);
    $directory = base_path('docs/samples/clinics');
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    foreach (['xlsx', 'pdf'] as $format) {
        $response = app(ClinicReports::class)->export($request, $facility, ['facility_id' => $facilityId, 'sort' => 'code'], $format);
        file_put_contents($directory.'/clinic-list.'.$format, $response->getContent());
    }
    DB::table('clinics')->where('id', $clinicIds[0])->update(['description' => str_repeat('هذا نص عربي اختباري مطوّل لمعاينة توزيع التوصيف عبر الصفحات والتحقق من سلامة اتجاه الكتابة واتصال الحروف. ', 45)]);
    $detail = app(ClinicReports::class)->export($request, $facility, ['facility_id' => $facilityId], 'pdf', $clinicIds[0]);
    file_put_contents($directory.'/clinic-detail.pdf', $detail->getContent());
    foreach (['clinic-list.pdf', 'clinic-detail.pdf'] as $file) {
        $reader = new Mpdf\Mpdf(['tempDir' => storage_path('framework/cache/clinic-pdf')]);
        echo $file.': '.$reader->setSourceFile($directory.'/'.$file)." pages\n";
    }
    echo "Test samples generated; all database fixtures will be rolled back.\n";
} finally {
    DB::rollBack();
}
