<?php

namespace Tests\Feature;

use App\Services\Catalog\CatalogReports;
use App\Services\Clinics\ClinicReports;
use App\Services\Doctors\DoctorReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogFixture;
use Tests\TestCase;

class DirectoryDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public static function descriptions(): array
    {
        return [['service', 'وصف الخدمة'], ['procedure', 'وصف الإجراء'], ['clinic', 'توصيف العيادة'], ['doctor', 'التوصيف المهني']];
    }

    #[DataProvider('descriptions')]
    public function test_detail_pdf_uses_the_correct_description_heading(string $kind, string $heading): void
    {
        $f = CatalogFixture::make();
        config(['clinics.doctor_staff_types' => ['CAT-'.$f['tag']]]);
        $facility = (array) DB::table('facilities')->find($f['facility']);
        $facility['today'] = $f['today'];
        $request = Request::create('/report');
        $request->setUserResolver(fn () => $f['user']);
        $document = null;
        View::composer('reports.directory', function ($view) use (&$document) {
            $document = $view->getData();
        });
        if (in_array($kind, ['service', 'procedure'], true)) {
            $response = app(CatalogReports::class)->export($request, $facility, [], 'pdf', $kind, $f['items'][$kind][1]);
        } elseif ($kind === 'clinic') {
            $id = DB::table('clinics')->insertGetId(['facility_id' => $f['facility'], 'code' => 'CL-REVIEW', 'name_ar' => 'عيادة اختبار', 'description' => 'وصف محفوظ']);
            $response = app(ClinicReports::class)->export($request, $facility, [], 'pdf', $id);
        } else {
            $id = DB::table('staff')->where('staff_code', 'CAT-'.$f['tag'])->value('id');
            $response = app(DoctorReports::class)->export($request, $facility, [], 'pdf', $id);
        }
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertNotNull($document);
        $html = view('reports.directory', $document)->render();
        $this->assertStringContainsString('<h2>'.$heading.'</h2>', $html);
        foreach (self::descriptions() as [$other, $label]) {
            if ($other !== $kind) {
                $this->assertStringNotContainsString('<h2>'.$label.'</h2>', $html);
            }
        }
        // Local-only artifacts from the real PDF renderer, for visual review.
        $directory = storage_path('framework/testing/catalog-description-review');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/'.$kind.'.pdf', $response->getContent());
    }
}
