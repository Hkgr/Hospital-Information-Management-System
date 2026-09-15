<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Assert;

final class DossierWorkbookAssertions
{
    public static function check(Spreadsheet $book, bool $landscape): void
    {
        Assert::assertGreaterThan(0, $book->getSheetCount());
        foreach ($book->getAllSheets() as $sheet) {
            $label = $sheet->getTitle();
            Assert::assertTrue($sheet->getRightToLeft(), $label);
            $setup = $sheet->getPageSetup();
            Assert::assertSame(9, $setup->getPaperSize(), $label);
            $continuation = str_starts_with($label, 'نصوص ') || str_starts_with($label, 'بيانات نصوص ');
            Assert::assertSame($landscape && ! $continuation ? 'landscape' : 'portrait', $setup->getOrientation(), $label);
            Assert::assertTrue($setup->getFitToPage(), $label);
            Assert::assertSame(1, $setup->getFitToWidth(), $label);
            Assert::assertSame(0, $setup->getFitToHeight(), $label);
            $area = $setup->getPrintArea();
            Assert::assertMatchesRegularExpression('/^A1:[A-Z]+[1-9][0-9]*$/', $area, $label);
            [, [$lastColumn, $lastRow]] = Coordinate::rangeBoundaries($area);
            Assert::assertSame($sheet->getHighestDataRow(), $lastRow, $label);
            Assert::assertSame(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), $lastColumn, $label);
            [$start, $end] = $setup->getRowsToRepeatAtTop();
            Assert::assertGreaterThan(0, $start, $label);
            Assert::assertSame($start, $end, $label);
            Assert::assertLessThanOrEqual($lastRow, $end, $label);
            Assert::assertSame('A'.($end + 1), $sheet->getFreezePane(), $label);
            Assert::assertSame('A'.$start.':'.Coordinate::stringFromColumnIndex($lastColumn).$lastRow, $sheet->getAutoFilter()->getRange(), $label);
            $margins = $sheet->getPageMargins();
            foreach ([$margins->getLeft(), $margins->getRight(), $margins->getTop(), $margins->getBottom()] as $margin) {
                Assert::assertGreaterThanOrEqual(0.3, $margin, $label);
                Assert::assertLessThanOrEqual(0.75, $margin, $label);
            }
            Assert::assertGreaterThan(0, $margins->getHeader(), $label);
            Assert::assertLessThan($margins->getTop(), $margins->getHeader(), $label);
            Assert::assertLessThan($margins->getBottom(), $margins->getFooter(), $label);
            Assert::assertStringStartsWith('&R&"Cairo,Regular"&9', $sheet->getHeaderFooter()->getOddHeader(), $label);
            Assert::assertStringStartsWith('&C&"Cairo,Regular"&9', $sheet->getHeaderFooter()->getOddFooter(), $label);
            foreach (['Cairo', '&P', '&N'] as $part) {
                Assert::assertStringContainsString($part, $sheet->getHeaderFooter()->getOddFooter(), $label);
            }
            for ($column = 1; $column <= $lastColumn; $column++) {
                $width = $sheet->getColumnDimensionByColumn($column)->getWidth();
                Assert::assertGreaterThan(3, $width, $label);
                Assert::assertLessThan(150, $width, $label);
            }
            for ($row = 1; $row <= $lastRow; $row++) {
                $height = $sheet->getRowDimension($row)->getRowHeight();
                Assert::assertGreaterThan(0, $height, $label.' row '.$row);
                Assert::assertLessThanOrEqual(409, $height, $label.' row '.$row);
            }
            foreach ($sheet->getCellCollection()->getCoordinates() as $address) {
                $cell = $sheet->getCell($address);
                if ($cell->getValue() === null) {
                    continue;
                }
                Assert::assertSame('Cairo', $cell->getStyle()->getFont()->getName(), $label.'!'.$address);
                Assert::assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType(), $label.'!'.$address);
                $caption = $sheet->getCell($cell->getColumn().$start)->getValue();
                $fact = $sheet->getCell('A'.$cell->getRow())->getValue();
                $date = in_array($caption, ['التاريخ', 'التاريخ الفعلي', 'تاريخ آخر زيارة', 'تاريخ الرفع'], true)
                    || ($caption === 'القيمة' && in_array($fact, ['الميلاد', 'تاريخ فتح الإضبارة'], true));
                if ($cell->getRow() > $end && $date && ! in_array($cell->getValue(), ['', 'غير مسجل'], true)) {
                    Assert::assertSame(DataType::TYPE_NUMERIC, $cell->getDataType(), 'Dedicated date: '.$label.'!'.$address);
                    Assert::assertTrue(Date::isDateTime($cell), $label.'!'.$address);
                }
                if (is_string($cell->getValue())) {
                    Assert::assertSame(DataType::TYPE_STRING, $cell->getDataType(), $label.'!'.$address);
                    Assert::assertStringNotContainsString('dossier-private', $cell->getValue());
                    Assert::assertDoesNotMatchRegularExpression('#(?:files|staging)/[0-9a-f-]{36}#i', $cell->getValue());
                } else {
                    Assert::assertSame(DataType::TYPE_NUMERIC, $cell->getDataType(), $label.'!'.$address);
                    if (Date::isDateTime($cell)) {
                        Assert::assertGreaterThan(0, $cell->getValue());
                        Assert::assertMatchesRegularExpression('/yyyy-mm-dd/', $cell->getStyle()->getNumberFormat()->getFormatCode());
                    }
                }
            }
        }
    }
}
