<?php

namespace Tests\Feature;

use App\Services\Directory\DirectoryReport;
use App\Services\Directory\ReportLayout;
use DOMDocument;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class DirectoryReportTest extends TestCase
{
    private function document(string $description): array
    {
        return [
            'metadata' => ['title' => 'قائمة العيادات', 'facility' => 'منشأة اختبارية', 'number' => 'CL-TEST', 'issuer' => 'اختبار', 'issued_at' => '2026-09-11', 'timezone' => 'Asia/Damascus', 'filters' => 'الحالة: فعال', 'definition' => 'تعريف المؤشر'],
            'detail' => false, 'linkTitle' => 'الأطباء الحاليون',
            'columns' => ['code', 'name_ar', 'description', 'patient_count'],
            'labels' => ['code' => 'كود العيادة', 'name_ar' => 'اسم العيادة', 'description' => 'التوصيف', 'patient_count' => 'عدد المرضى'],
            'rows' => [['id' => 42, 'name_ar' => '=HYPERLINK("bad")', 'code' => '0001', 'description' => $description, 'patient_count' => 0, 'details' => ['عدد المرضى' => 0], 'links' => []]],
        ];
    }

    public function test_pdf_keeps_moderate_text_in_selected_table_and_flows_long_text_with_record_reference(): void
    {
        $moderate = str_repeat('تقييم مهنيّ ومتابعة طبيّة دورية بالتنسيق مع فريق الرعاية. ', 5);
        $document = $this->document($moderate);
        $html = view('reports.directory', $document)->render();
        $dom = new DOMDocument;
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $this->assertSame(4, $xpath->query('//table[contains(@class,"data")]/thead/tr/th')->length);
        $this->assertStringContainsString($moderate, $xpath->query('//table[contains(@class,"data")]/tbody')->item(0)->textContent);
        $this->assertStringNotContainsString('ملحق 1', $dom->textContent);
        $long = str_repeat($moderate, 15).' نهاية كاملة';
        $html = view('reports.directory', $this->document($long))->render();
        $this->assertStringContainsString($long, $html);
        $this->assertStringContainsString('ملحق 1', $html);
        $dom->loadHTML($html);
        $reference = (new DOMXPath($dom))->query('//p[@class="reference"]')->item(0)->textContent;
        $this->assertStringContainsString('0001', $reference);
        $this->assertStringContainsString('=HYPERLINK("bad")', $reference);
        $detail = $this->document('');
        $detail['detail'] = true;
        $this->assertStringContainsString('توصيف العيادة', view('reports.directory', $detail)->render());
        $pdf = app(DirectoryReport::class)->response($document, 'pdf')->getContent();
        $this->assertStringContainsString('/FontFile2', $pdf);
        $this->assertStringContainsString('Cairo-Regular', $pdf);
        $this->assertStringContainsString('Cairo-Bold', $pdf);
    }

    public function test_xlsx_keeps_complete_cell_values_types_and_ordered_print_continuations(): void
    {
        $long = str_repeat('نص كامل للتحقق من الترتيب دون فقد المسافات أو التشكيل. ', 90).' النهاية';
        $book = $this->workbook($this->document($long));
        try {
            $sheet = $book->getSheet(0);
            $this->assertSame('0001', $sheet->getCell('A9')->getValue());
            $this->assertSame('s', $sheet->getCell('A9')->getDataType());
            $this->assertSame('s', $sheet->getCell('B9')->getDataType());
            $this->assertSame('=HYPERLINK("bad")', $sheet->getCell('B9')->getValue());
            $this->assertSame($long, $sheet->getCell('C9')->getValue());
            $this->assertSame(0, $sheet->getCell('D9')->getValue());
            $this->assertSame('n', $sheet->getCell('D9')->getDataType());
            $this->assertSame('A9', $sheet->getFreezePane());
            $this->assertSame('A8:D9', $sheet->getAutoFilter()->getRange());
            $printed = $book->getSheetByName('النصوص للطباعة');
            $this->assertNotNull($printed);
            $this->assertSame($long, implode('', array_column(array_slice($printed->toArray(), 2), 3)));
            $this->assertStringContainsString("42\n0001", $printed->getCell('B3')->getValue());
            foreach ($book->getAllSheets() as $part) {
                $this->assertTrue($part->getRightToLeft());
                $this->assertSame(1, $part->getPageSetup()->getFitToWidth());
                $this->assertSame(0, $part->getPageSetup()->getFitToHeight());
                foreach ($part->getCellCollection()->getCoordinates() as $coordinate) {
                    $cell = $part->getCell($coordinate);
                    $this->assertSame('Cairo', $cell->getStyle()->getFont()->getName());
                    $this->assertNotSame('f', $cell->getDataType());
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_excel_actual_cell_limit_preserves_unicode_and_full_ordered_storage(): void
    {
        $long = str_repeat('نص🙂 ', 7000).'آخر النص';
        $parts = ReportLayout::cellParts($long);
        $this->assertGreaterThan(1, count($parts));
        $this->assertSame($long, implode('', $parts));
        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(32767, strlen(mb_convert_encoding($part, 'UTF-16LE', 'UTF-8')) / 2);
        }
        $book = $this->workbook($this->document($long));
        try {
            $storage = $book->getSheetByName('بيانات النصوص');
            $this->assertNotNull($storage);
            $stored = array_slice($storage->toArray(formatData: false), 1);
            $this->assertSame($long, implode('', array_column($stored, 4)));
            $this->assertSame(range(1, count($stored)), array_column($stored, 3));
            $this->assertSame([42], array_values(array_unique(array_column($stored, 0))));
            $this->assertSame(['0001'], array_values(array_unique(array_column($stored, 1))));
            $this->assertSame(['التوصيف'], array_values(array_unique(array_column($stored, 2))));
            $printed = $book->getSheetByName('النصوص للطباعة');
            $this->assertSame($long, implode('', array_column(array_slice($printed->toArray(), 2), 3)));
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function workbook(array $document): Spreadsheet
    {
        $path = tempnam(storage_path('framework/testing'), 'report');
        try {
            file_put_contents($path, app(DirectoryReport::class)->response($document, 'xlsx')->getContent());

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }
}
