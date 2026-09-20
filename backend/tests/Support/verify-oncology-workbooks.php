<?php

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PHPUnit\Framework\Assert;
use Tests\Support\DossierWorkbookAssertions;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$folder = $argv[1] ?? basePath();
foreach (['card', 'visit', 'list'] as $kind) {
    $book = IOFactory::load($folder.'/'.$kind.'.xlsx');
    DossierWorkbookAssertions::check($book, $kind === 'list');
    $dates = 0;
    $numbers = 0;
    $leadingZero = 0;
    $injection = 0;
    $values = [];
    foreach ($book->getAllSheets() as $sheet) {
        foreach ($sheet->getCellCollection()->getCoordinates() as $address) {
            $cell = $sheet->getCell($address);
            $v = $cell->getValue();
            $values[] = $v;
            if ($cell->getDataType() === DataType::TYPE_NUMERIC) {
                if (Date::isDateTime($cell)) {
                    $dates++;
                } else {
                    $numbers++;
                }
            }
            if ($v === '00012') {
                Assert::assertSame(DataType::TYPE_STRING, $cell->getDataType());
                $leadingZero++;
            }
            if (is_string($v) && str_starts_with($v, '=بروتوكول')) {
                Assert::assertSame(DataType::TYPE_STRING, $cell->getDataType());
                $injection++;
            }
        }
    }
    Assert::assertGreaterThan(0, $dates);
    Assert::assertGreaterThan(0, $numbers);
    if ($kind === 'card') {
        Assert::assertGreaterThan(0, $leadingZero);
        Assert::assertGreaterThan(0, $injection);
        Assert::assertContains('إعطاء مبطل — محفوظ تاريخيًا', $values);
        Assert::assertContains('إعطاء اصطناعي مسجل خطأ', $values);
    }
    if ($kind === 'visit') {
        Assert::assertNotContains('إعطاء مبطل — محفوظ تاريخيًا', $values);
        Assert::assertNotContains('إعطاء اصطناعي مسجل خطأ', $values);
        Assert::assertContains('إعطاء مبطل — الصرف واقعة مستقلة', $values);
    }
    echo "$kind: {$book->getSheetCount()} valid RTL/Cairo sheets; $dates typed dates, $numbers numeric cells; $leadingZero leading-zero codes, $injection formula-like safe texts; print properties verified.\n";
    $book->disconnectWorksheets();
}
function basePath(): string
{
    return dirname(__DIR__, 3).'/frontend/.superdesign/tmp/oncology';
}
