<?php

namespace App\Services\Reports;

use App\Services\Directory\DirectoryReport;
use App\Services\Directory\ReportLayout;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AnonymousStatisticsReport
{
    public function render(array $data, string $format, string $issued): string
    {
        if ($format === 'pdf') {
            return app(DirectoryReport::class)->pdf(['detail' => true, 'columns' => [], 'data' => $data,
                'metadata' => ['title' => $data['title'], 'number' => 'STATISTICS', 'facility' => $data['facility']['name_ar'], 'issued_at' => $issued, 'timezone' => $data['facility']['timezone'], 'issuer' => 'نظام المشفى']], 'reports.anonymous-statistics');
        }
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Hospital')->setTitle($data['title']);
        $book->getDefaultStyle()->getFont()->setName('Cairo')->setSize(10.5);
        $sheet = $book->getActiveSheet()->setTitle('الإحصاءات المجهلة')->setRightToLeft(true);
        $text = fn ($cell, $value) => $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
        foreach ([$data['title'], $data['facility']['name_ar'], $data['filters']['from_month'].' — '.$data['filters']['to_month'], 'وقت الإصدار: '.$issued.' '.$data['facility']['timezone'], $data['privacy']['policy'], $data['occupancy']['reason']] as $i => $value) {
            $text('A'.($i + 1), $value);
            $sheet->mergeCells('A'.($i + 1).':F'.($i + 1));
            $sheet->getRowDimension($i + 1)->setRowHeight($i === 4 ? 48 : 28);
        }
        $labels = ['الشهر', 'المؤشر', 'الفئة', 'مرضى فريدون', 'الوقائع', 'التعريف'];
        foreach ($labels as $i => $label) {
            $text([$i + 1, 8], $label);
        }
        $row = 9;
        foreach ($data['months'] as $month) {
            foreach ($month['sections'] as $section) {
                $rows = $section['suppressed'] ? [['label' => 'محجوب لحماية الخصوصية', 'patients' => null, 'events' => null]] : [
                    ['label' => 'إجمالي المؤشر (لا تجمع المرضى بين الفئات)', 'patients' => $section['patients'], 'events' => $section['events']], ...$section['rows']];
                foreach ($rows as $values) {
                    foreach ([$month['month'], $section['title'], $values['label'], $values['patients'], $values['events'], $section['definition']] as $i => $value) {
                        if (is_int($value)) {
                            $sheet->setCellValueExplicit([$i + 1, $row], $value, DataType::TYPE_NUMERIC);
                        } else {
                            $text([$i + 1, $row], $value ?? 'محجوب');
                        }
                    }
                    $height = max(30, 6 + 17 * max(ReportLayout::lines($values['label'], 55), ReportLayout::lines($section['definition'], 70)));
                    if ($height > 400) {
                        throw new \RuntimeException('Statistics label exceeds safe print height.');
                    }
                    $sheet->getRowDimension($row++)->setRowHeight($height);
                }
            }
        }
        foreach ([15, 18, 43, 17, 15, 52] as $i => $width) {
            $sheet->getColumnDimensionByColumn($i + 1)->setWidth($width);
        }
        $sheet->getStyle('A1:F'.($row - 1))->getAlignment()->setWrapText(true)->setVertical('center')->setHorizontal('right');
        $sheet->getStyle('A8:F8')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A8:F8')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF155C56');
        $sheet->getRowDimension(8)->setRowHeight(32);
        $sheet->freezePane('A9')->setAutoFilter('A8:F'.($row - 1));
        $sheet->getPageSetup()->setPaperSize(9)->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:F'.($row - 1))->setRowsToRepeatAtTopByStartAndEnd(8, 8);
        $sheet->getPageMargins()->setTop(.45)->setBottom(.45)->setLeft(.3)->setRight(.3)->setHeader(.18)->setFooter(.2);
        $sheet->getHeaderFooter()->setOddHeader('&R&"Cairo,Regular"&9الإحصاءات المجهلة')->setOddFooter('&C&"Cairo,Regular"&9&P / &N');
        $stream = fopen('php://memory', 'w+b');
        try {
            (new Xlsx($book))->save($stream);
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
            $book->disconnectWorksheets();
        }
    }
}
