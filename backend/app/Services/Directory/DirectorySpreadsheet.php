<?php

namespace App\Services\Directory;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DirectorySpreadsheet
{
    public function render(array $document): string
    {
        $book = new Spreadsheet;
        $meta = $document['metadata'];
        $book->getDefaultStyle()->getFont()->setName('Cairo')->setSize(10.5)->getColor()->setARGB('FF233F3C');
        $book->getProperties()->setCreator($meta['issuer'])->setTitle($meta['title'])->setSubject($meta['number']);
        $sheet = $book->getActiveSheet()->setTitle($meta['title'])->setRightToLeft(true);
        $columns = $document['columns'];
        $widths = ReportLayout::widths($columns);
        $count = count($columns);
        foreach ($columns as $index => $key) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(ReportLayout::excelWidth($widths[$key]));
        }
        $this->header($sheet, $meta, $count, array_sum($widths));
        $longTexts = [];
        $overflow = [];
        foreach ($columns as $index => $key) {
            $this->text($sheet, $index + 1, 8, $document['labels'][$key]);
        }
        foreach ($document['rows'] as $index => $row) {
            $line = $index + 9;
            $height = 25;
            foreach ($columns as $col => $key) {
                $value = $key === 'number' ? $index + 1 : ($row[$key] ?? '');
                $cell = $sheet->getCell([$col + 1, $line]);
                $display = (string) $value;
                if (is_string($value)) {
                    $parts = ReportLayout::cellParts($value);
                    $lines = ReportLayout::lines($value, $widths[$key] - 3);
                    if ($lines > 16 || count($parts) > 1) {
                        $reference = count($longTexts) + 1;
                        $longTexts[] = ['id' => $row['id'], 'code' => $row['code'], 'name' => $row['name'] ?? $row['name_ar'], 'field' => $document['labels'][$key], 'text' => $value];
                        $display = mb_substr($value, 0, 85).'… [النص الكامل '.$reference.']';
                        if (count($parts) === 1) {
                            // Full sortable/editable value stays in this cell. The explicit
                            // display excerpt avoids Excel's hard 409-point printed row cap.
                            $cell->setValueExplicit($value, DataType::TYPE_STRING);
                            $cell->getStyle()->getNumberFormat()->setFormatCode(';;;"'.str_replace('"', '""', $display).'"');
                        } else {
                            $cell->setValueExplicit('النص يتجاوز حد خلية Excel؛ النص الكامل '.$reference, DataType::TYPE_STRING);
                            foreach ($parts as $part => $text) {
                                $overflow[] = [$row['id'], $row['code'], $document['labels'][$key], $part + 1, $text];
                            }
                        }
                        $sheet->getComment($cell->getCoordinate())->getText()->createText('النص الكامل محفوظ '.(count($parts) === 1 ? 'في هذه الخلية (شريط الصيغة)، وله نسخة كاملة للطباعة في ورقة النصوص للطباعة.' : 'بالترتيب في ورقة بيانات النصوص، وله نسخة كاملة في ورقة النصوص للطباعة.'));
                        $cell->getHyperlink()->setUrl("sheet://'النصوص للطباعة'!A1");
                    } else {
                        $cell->setValueExplicit($value, DataType::TYPE_STRING);
                    }
                } else {
                    $cell->setValueExplicit($value, ReportLayout::numeric($key) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
                // Counts from MySQL may be strings; always preserve their numeric type.
                if (ReportLayout::numeric($key)) {
                    $cell->setValueExplicit((int) $value, DataType::TYPE_NUMERIC);
                }
                $height = max($height, 4 + ReportLayout::lines($display, $widths[$key] - 3) * 23.25);
                $cell->getStyle()->getAlignment()->setHorizontal(ReportLayout::numeric($key) || $key === 'is_active' ? 'center' : ($key === 'code' ? 'left' : 'right'));
                if ($key === 'code') {
                    $cell->getStyle()->getAlignment()->setReadOrder(1);
                }
            }
            $sheet->getRowDimension($line)->setRowHeight(min(400, $height));
        }
        $last = max(8, 8 + count($document['rows']));
        $this->table($sheet, 8, $last, $count);
        $this->printSetup($sheet, $count, $last, 8, $meta, $count > 4);
        if ($longTexts) {
            $this->mergedText($sheet, 1, $count, 7, 'النصوص الطويلة: يظهر مقتطف معلّم؛ النص محفوظ في الخلية وتستكمله ورقة «النصوص للطباعة».');
            $sheet->getRowDimension(7)->setRowHeight(19);
            $this->printTexts($book, $longTexts, $meta);
        }
        if ($overflow) {
            $extra = $book->createSheet()->setTitle('بيانات النصوص')->setRightToLeft(true);
            foreach ([14, 24, 35, 12, 100] as $col => $width) {
                $extra->getColumnDimensionByColumn($col + 1)->setWidth(ReportLayout::excelWidth($width));
            }
            foreach (['معرّف السجل', 'الكود', 'الحقل', 'الجزء', 'النص الكامل — يُضم بالترتيب دون فواصل إضافية'] as $col => $label) {
                $this->text($extra, $col + 1, 1, $label);
            }
            foreach ($overflow as $index => $values) {
                foreach ($values as $col => $value) {
                    $extra->setCellValueExplicit([$col + 1, $index + 2], $value, in_array($col, [0, 3]) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
                $extra->getRowDimension($index + 2)->setRowHeight(48);
            }
            $this->table($extra, 1, count($overflow) + 1, 5);
            $this->printSetup($extra, 5, count($overflow) + 1, 1, $meta, false);
            // Storage sheet is usable in Excel; the separate complete text sheet is
            // the print representation, since a 32767-unit cell cannot fit one row.
            $extra->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        }
        $book->setActiveSheetIndex(0);
        $stream = fopen('php://temp', 'w+b');
        try {
            (new Xlsx($book))->save($stream);
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
            $book->disconnectWorksheets();
            unset($book);
            gc_collect_cycles();
        }
    }

    private function text(Worksheet $sheet, int $col, int $row, string $text): void
    {
        $sheet->setCellValueExplicit([$col, $row], $text, DataType::TYPE_STRING);
    }

    private function mergedText(Worksheet $sheet, int $first, int $last, int $row, string $text): void
    {
        $this->text($sheet, $first, $row, $text);
        if ($first < $last) {
            $sheet->mergeCells([$first, $row, $last, $row]);
        }
    }

    private function header(Worksheet $sheet, array $meta, int $count, float $width): void
    {
        $drawing = new Drawing;
        $drawing->setPath(resource_path('reports/logo-ar-color.png'))->setName('هوية المشفى')->setHeight(56)->setCoordinates('A1')->setOffsetY(4)->setWorksheet($sheet);
        $sheet->getRowDimension(1)->setRowHeight(47);
        $this->mergedText($sheet, 1, $count, 2, $meta['title'].' · مشفى محمد بن زايد الإماراتي');
        $sheet->getStyle([1, 2, $count, 2])->getFont()->setBold(true)->setSize(18)->getColor()->setARGB('FF155C56');
        $sheet->getRowDimension(2)->setRowHeight(34);
        $pairs = [
            ['رقم التقرير: '.$meta['number'], 'الإصدار: '.$meta['issued_at'].' · '.$meta['timezone']],
            ['المنشأة: '.$meta['facility'], 'أصدره: '.$meta['issuer']],
        ];
        foreach ($pairs as $index => [$right, $left]) {
            if ($count >= 4) {
                $middle = intdiv($count, 2);
                $this->mergedText($sheet, 1, $middle, $index + 3, $right);
                $this->mergedText($sheet, $middle + 1, $count, $index + 3, $left);
            } else {
                $this->mergedText($sheet, 1, $count, $index + 3, $right.' | '.$left);
            }
            $sheet->getRowDimension($index + 3)->setRowHeight(30);
        }
        $this->mergedText($sheet, 1, $count, 5, 'نطاق التقرير: '.$meta['filters']);
        $sheet->getRowDimension(5)->setRowHeight(8 + ReportLayout::lines($meta['filters'], $width - 12, 9) * 14);
        $this->mergedText($sheet, 1, $count, 6, 'احتساب المرضى: '.$meta['definition']);
        $sheet->getRowDimension(6)->setRowHeight(8 + ReportLayout::lines($meta['definition'], $width - 12, 9) * 14);
        $sheet->getRowDimension(7)->setRowHeight(8);
        $sheet->getStyle([1, 3, $count, 7])->getFont()->setSize(9)->getColor()->setARGB('FF36564E');
        $sheet->getStyle([1, 2, $count, 7])->getAlignment()->setHorizontal('right')->setVertical('center')->setWrapText(true);
    }

    private function table(Worksheet $sheet, int $header, int $last, int $count): void
    {
        $sheet->getStyle([1, $header, $count, $last])->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->getStyle([1, $header, $count, $header])->getFill()->setFillType('solid')->getStartColor()->setARGB('FF155C56');
        $sheet->getStyle([1, $header, $count, $header])->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle([1, $header, $count, $header])->getAlignment()->setHorizontal('center');
        $sheet->getRowDimension($header)->setRowHeight(29);
        for ($row = $header + 1; $row <= $last; $row++) {
            $style = $sheet->getStyle([1, $row, $count, $row]);
            $style->getBorders()->getBottom()->setBorderStyle('hair')->getColor()->setARGB('FFDCE6E3');
            if (($row - $header) % 2 === 0) {
                $style->getFill()->setFillType('solid')->getStartColor()->setARGB('FFF3F7F6');
            }
        }
        $sheet->freezePane('A'.($header + 1))->setAutoFilter([1, $header, $count, $last]);
    }

    private function printSetup(Worksheet $sheet, int $count, int $last, int $header, array $meta, bool $landscape): void
    {
        $sheet->getPageSetup()->setOrientation($landscape ? 'landscape' : 'portrait')->setPaperSize(9)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd($header, $header)->setPrintArea('A1:'.Coordinate::stringFromColumnIndex($count).$last);
        $sheet->getPageMargins()->setLeft(0.47)->setRight(0.47)->setTop(0.35)->setBottom(0.5)->setFooter(0.2);
        $sheet->getHeaderFooter()->setOddFooter('&"Cairo,Regular"&9&C'.$meta['number'].' | &P / &N');
        $sheet->setShowGridlines(false);
    }

    private function printTexts(Spreadsheet $book, array $texts, array $meta): void
    {
        $sheet = $book->createSheet()->setTitle('النصوص للطباعة')->setRightToLeft(true);
        foreach ([14, 26, 42, 104] as $col => $width) {
            $sheet->getColumnDimensionByColumn($col + 1)->setWidth(ReportLayout::excelWidth($width));
        }
        $this->mergedText($sheet, 1, 4, 1, 'النصوص الكاملة · '.$meta['title'].' · '.$meta['number']);
        $sheet->getStyle('A1:D1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF155C56');
        $sheet->getRowDimension(1)->setRowHeight(33);
        foreach (['مرجع', 'معرّف / كود', 'السجل / الحقل', 'النص الكامل — متابعة بالترتيب'] as $col => $label) {
            $this->text($sheet, $col + 1, 2, $label);
        }
        $line = 3;
        foreach ($texts as $index => $item) {
            foreach (ReportLayout::printParts($item['text'], 101) as $part => $text) {
                foreach ([(string) ($index + 1).' / '.($part + 1), $item['id']."\n".$item['code'], $item['name']."\n".$item['field'], $text] as $col => $value) {
                    $this->text($sheet, $col + 1, $line, $value);
                }
                $sheet->getRowDimension($line)->setRowHeight(5 + max(ReportLayout::lines($text, 101), ReportLayout::lines($item['name']."\n".$item['field'], 39)) * 23.25);
                $line++;
            }
        }
        $this->table($sheet, 2, $line - 1, 4);
        $sheet->getStyle('A3:D'.($line - 1))->getAlignment()->setVertical('top')->setHorizontal('right');
        $this->printSetup($sheet, 4, $line - 1, 2, $meta, false);
    }
}
