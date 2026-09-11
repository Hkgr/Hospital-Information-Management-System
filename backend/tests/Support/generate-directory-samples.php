<?php

use App\Models\User;
use App\Services\Clinics\ClinicReports;
use App\Services\Directory\DirectoryReport;
use App\Services\Doctors\DoctorReports;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$comparison = base_path('docs/samples/comparison');
$capture = in_array('--capture-baseline', $argv, true);
if ($capture && file_exists($comparison.'/documents.json')) {
    throw new RuntimeException('The captured before/after documents are immutable. Use replay-directory-samples.php.');
}
if (! is_dir($comparison)) {
    mkdir($comparison, 0775, true);
}
$renderer = new class extends DirectoryReport
{
    public array $document = [];

    public function response(array $document, string $format): Response
    {
        $this->document = $document;

        return parent::response($document, $format);
    }
};
$app->instance(DirectoryReport::class, $renderer);
$documents = [];
config(['clinics.doctor_staff_types' => ['SAMPLE_DOCTOR']]);
DB::beginTransaction();
try {
    $suffix = Str::upper(Str::random(4));
    $user = User::factory()->create(['username' => 'report-'.$suffix, 'name' => 'مُصدر التقارير الاختبارية']);
    $facilityId = DB::table('facilities')->insertGetId(['code' => 'SAMPLE-'.$suffix, 'name_ar' => 'مشفى محمد بن زايد الإماراتي — بيانات اختبارية', 'timezone' => 'Asia/Damascus']);
    $facility = ['id' => $facilityId, 'name_ar' => 'مشفى محمد بن زايد الإماراتي — بيانات اختبارية', 'timezone' => 'Asia/Damascus', 'today' => now('Asia/Damascus')->toDateString(), 'permissions' => ['clinics.view']];
    $type = DB::table('staff_types')->where('code', 'SAMPLE_DOCTOR')->value('id') ?? DB::table('staff_types')->insertGetId(['code' => 'SAMPLE_DOCTOR', 'name_ar' => 'طبيب اختصاصي اختباري']);
    $specialty = DB::table('specialties')->insertGetId(['code' => 'SP-'.$suffix, 'name_ar' => 'الطب الداخلي والرعاية المستمرة']);
    $doctors = [];
    $clinics = [];
    for ($i = 1; $i <= 40; $i++) {
        $doctor = DB::table('staff')->insertGetId(['staff_code' => $suffix.'-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'full_name' => ['أحمد الاختباري', 'ليلى التجريبية', 'سامر النموذجي', 'نور الاختبارية'][$i % 4].' '.$i, 'search_name' => 'اختباري', 'staff_type_id' => $type, 'description' => 'بيانات اختبارية — متابعة الحالات الطبية والتقييم الدوري بالتنسيق مع فريق العيادة.', 'license_no' => '000'.$i, 'phone' => '009630000000']);
        DB::table('staff_specialties')->insert(['staff_id' => $doctor, 'specialty_id' => $specialty]);
        $doctors[] = $doctor;
        $clinics[] = DB::table('clinics')->insertGetId(['facility_id' => $facilityId, 'code' => str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'name_ar' => ['العيادة الداخلية', 'عيادة الأطفال', 'العيادة القلبية', 'عيادة الجراحة العامة'][$i % 4].' — اختبار '.$i, 'specialty_id' => $specialty, 'description' => 'رعاية طبية وتقييم ومتابعة ضمن المنشأة. هذه البيانات اصطناعية للمعاينة فقط.']);
    }
    foreach ($doctors as $i => $doctor) {
        foreach ([$clinics[$i], $clinics[($i + 1) % count($clinics)]] as $clinic) {
            DB::table('clinic_staff')->insert(['staff_id' => $doctor, 'clinic_id' => $clinic, 'starts_on' => now('Asia/Damascus')->subMonth()->toDateString()]);
        }
    }
    $long = str_repeat('نص اختباري طويل لمراجعة اتصال حروف العربية وتوزيعها عبر الصفحات دون قص أو تصغير. تشمل المتابعة التنسيق بين الاختصاصات وخطة الرعاية المستمرة. ', 30).' نهاية النص الاختباري الكامل.';
    DB::table('staff')->where('id', $doctors[0])->update(['description' => $long]);
    DB::table('clinics')->where('id', $clinics[0])->update(['description' => $long]);
    $moderate = 'تقييم الحالات المزمنة ووضع خطة متابعة فردية بالتنسيق مع فريق الرعاية. يشمل العمل مراجعة الاستجابة للعلاج، وتوثيق التوصيات المهنية، والتواصل مع العيادات المرتبطة لضمان استمرارية الرعاية. هذا توصيف اصطناعي متوسط الطول يجب أن يبقى مقروءًا داخل الجدول.';
    foreach (array_slice($doctors, 1, 3) as $doctor) {
        DB::table('staff')->where('id', $doctor)->update(['description' => $moderate]);
    }
    foreach (array_slice($clinics, 1, 3) as $clinic) {
        DB::table('clinics')->where('id', $clinic)->update(['description' => $moderate]);
    }
    DB::table('staff')->where('id', $doctors[1])->update(['full_name' => 'الدكتورة ليلى عبد الرحمن الاختبارية اختصاصية الطب الداخلي والرعاية المستمرة']);
    DB::table('clinics')->where('id', $clinics[1])->update(['name_ar' => 'عيادة الطب الداخلي والمتابعة المتكاملة للأمراض المزمنة — بيانات اصطناعية']);
    $request = Request::create('/api/doctors/export/pdf');
    $request->setUserResolver(fn () => $user);
    foreach (['doctors' => [DoctorReports::class, $doctors[0], 'doctor', ['code', 'name']], 'clinics' => [ClinicReports::class, $clinics[0], 'clinic', ['code', 'name_ar']]] as $directory => [$service, $id, $prefix, $compact]) {
        $path = $capture ? $comparison.'/before/'.$directory : base_path('docs/samples/'.$directory);
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }
        foreach (['pdf', 'xlsx'] as $format) {
            foreach (['list' => [], 'compact' => ['columns' => $compact], 'short' => ['search' => 'تقييم الحالات المزمنة', 'columns' => [$compact[0], $compact[1], 'description', 'patient_count']], 'single' => ['search' => 'تقييم الحالات المزمنة', 'columns' => [$compact[1]]]] as $kind => $filters) {
                $response = app($service)->export($request, $facility, $filters + ['sort' => 'code', 'direction' => 'asc', 'status' => 'active'], $format);
                file_put_contents($path.'/'.$prefix.'-'.$kind.'.'.$format, $response->getContent());
                $documents[$directory.'/'.$prefix.'-'.$kind.'.'.$format] = $renderer->document;
            }
        }
        file_put_contents($path.'/'.$prefix.'-detail.pdf', app($service)->export($request, $facility, [], 'pdf', $id)->getContent());
        $documents[$directory.'/'.$prefix.'-detail.pdf'] = $renderer->document;
    }
    if ($capture) {
        file_put_contents($comparison.'/documents.json', json_encode($documents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
    echo "Generated doctors + clinics list/compact XLSX and PDF, plus long detail PDFs. 40 synthetic rows each; all fixtures roll back.\n";
} finally {
    DB::rollBack();
}
