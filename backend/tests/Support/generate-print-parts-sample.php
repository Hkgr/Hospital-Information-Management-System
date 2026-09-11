<?php

use App\Services\Directory\DirectoryReport;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('Synthetic print sample requires testing.');
}
// Entirely synthetic: no database reads or writes.
$document = [
    'metadata' => ['title' => 'قائمة الأطباء', 'facility' => 'منشأة اختبارية', 'number' => 'DR-PRINT-PARTS', 'issuer' => 'اختبار الطباعة', 'issued_at' => '2026-09-11', 'timezone' => 'Asia/Damascus', 'filters' => 'عينة اصطناعية لاختبار النص المتصل', 'definition' => 'أعداد اصطناعية صفرية'],
    'detail' => false, 'linkTitle' => 'العيادات الحالية',
    'columns' => ['code', 'name', 'description', 'patient_count'],
    'labels' => ['code' => 'كود الطبيب', 'name' => 'اسم الطبيب', 'description' => 'التوصيف المهني', 'patient_count' => 'عدد المرضى'],
    'rows' => [],
];
foreach ([800, 2400] as $index => $length) {
    $document['rows'][] = [
        'id' => $index + 1, 'code' => '000'.($index + 1), 'name' => 'طبيب اختباري '.($index + 1),
        'description' => str_repeat('W', $length).($index === 0 ? '' : ' END-2400'),
        'patient_count' => 0, 'details' => [], 'links' => [],
    ];
}
$path = base_path('docs/samples/doctors/doctor-print-parts.xlsx');
file_put_contents($path, app(DirectoryReport::class)->response($document, 'xlsx')->getContent());
echo $path."\n";
