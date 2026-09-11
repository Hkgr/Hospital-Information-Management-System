<?php

namespace Tests\Feature;

use App\Services\Directory\DirectoryReport;
use App\Services\Directory\ReportLayout;
use DOMDocument;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DirectoryReportTest extends TestCase
{
    public static function printTexts(): array
    {
        return [
            '800 connected W' => [str_repeat('W', 800)],
            'much longer connected text' => [str_repeat('W', 12800)],
            'Arabic marks and supplementary Unicode' => [str_repeat('مُتَابَعَةٌ🙂𐍈', 350)],
            'spaces tabs and newlines' => ["  \t".str_repeat("نَصٌّ  W🙂\r\n\nمتابعة\t", 90)." \n"],
            'long whitespace run' => [str_repeat(" \r\n", 80)],
            'short' => ['نَصّ🙂 قصير'],
            'empty' => [''],
        ];
    }

    #[DataProvider('printTexts')]
    public function test_print_parts_preserve_exact_text_and_always_fit_the_line_budget(string $text): void
    {
        $parts = ReportLayout::printParts($text, 101);
        $this->assertSame($text, implode('', $parts));
        if ($text === '') {
            $this->assertSame([], $parts);
        } else {
            $this->assertNotEmpty($parts);
        }
        foreach ($parts as $part) {
            $this->assertNotSame('', $part);
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
            $this->assertLessThanOrEqual(14, ReportLayout::lines($part, 101));
        }
    }

    public function test_print_parts_handle_the_exact_measured_boundary_and_one_character_beyond(): void
    {
        $length = 1;
        while (ReportLayout::lines(str_repeat('W', $length + 1), 101) <= 14) {
            $length++;
        }
        $boundary = str_repeat('W', $length);
        $this->assertSame(14, ReportLayout::lines($boundary, 101));
        $this->assertSame([$boundary], ReportLayout::printParts($boundary, 101));
        $parts = ReportLayout::printParts($boundary.'W', 101);
        $this->assertGreaterThan(1, count($parts));
        $this->assertSame($boundary.'W', implode('', $parts));
        foreach ($parts as $part) {
            $this->assertNotSame('', $part);
            $this->assertLessThanOrEqual(14, ReportLayout::lines($part, 101));
        }
    }

    public static function printWorkbooks(): array
    {
        return [
            '800 W' => [str_repeat('W', 800), 'عيادة اختبارية'],
            '12800 W' => [str_repeat('W', 12800), 'عيادة اختبارية'],
            'Arabic Unicode' => [str_repeat('مُتَابَعَةٌ🙂𐍈', 350), 'عيادة اختبارية'],
            'mixed whitespace' => ["  \t".str_repeat("نَصٌّ  W🙂\r\n\nمتابعة\t", 90)." \n", 'عيادة اختبارية'],
            'long record identity' => [str_repeat('W', 800), str_repeat('W', 200)],
        ];
    }

    #[DataProvider('printWorkbooks')]
    public function test_connected_text_xlsx_preserves_values_and_print_rows_fit_without_clamping(string $text, string $name): void
    {
        $document = $this->document($text);
        $document['rows'][0]['name_ar'] = $name;
        $book = $this->workbook($document);
        try {
            $data = $book->getSheet(0);
            $this->assertSame($text, $data->getCell('C9')->getValue());
            $this->assertSame('s', $data->getCell('C9')->getDataType());
            $this->assertSame($name, $data->getCell('B9')->getValue());
            $this->assertSame('0001', $data->getCell('A9')->getValue());
            $this->assertSame('s', $data->getCell('A9')->getDataType());
            $this->assertSame(0, $data->getCell('D9')->getValue());
            $this->assertSame('n', $data->getCell('D9')->getDataType());
            $printed = $book->getSheetByName('النصوص للطباعة');
            $this->assertNotNull($printed);
            $this->assertSame($text, implode('', array_column(array_slice($printed->toArray(formatData: false), 2), 3)));
            if (ReportLayout::lines($name."\nالتوصيف", 39) > 14) {
                $this->assertSame($name."\nالتوصيف", implode('', array_column(array_slice($printed->toArray(formatData: false), 2), 2)));
            }
            foreach (range(3, $printed->getHighestDataRow()) as $row) {
                $height = $printed->getRowDimension($row)->getRowHeight();
                $this->assertLessThanOrEqual(409, $height);
                foreach (['A' => 11, 'B' => 23, 'C' => 39, 'D' => 101] as $column => $width) {
                    $cell = $printed->getCell($column.$row);
                    $lines = ReportLayout::lines((string) $cell->getValue(), $width);
                    $this->assertLessThanOrEqual(14, $lines);
                    $this->assertGreaterThanOrEqual(5 + $lines * 23.25, $height);
                    $this->assertSame('s', $cell->getDataType());
                    $this->assertSame(10.5, $cell->getStyle()->getFont()->getSize());
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

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
        $long = " \r\n".str_repeat('نص🙂 ', 7000)."آخر النص\r\n ";
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

            // The reader otherwise normalizes CRLF too, hiding literal storage fidelity.
            return IOFactory::createReader('Xlsx')
                ->setValueBinder((new DefaultValueBinder)->setPreserveCr(true))
                ->load($path);
        } finally {
            unlink($path);
        }
    }
}
