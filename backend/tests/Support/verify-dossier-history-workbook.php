<?php

// Read-only review-artifact check. No application bootstrap or database connection.
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DossierWorkbookAssertions;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$path = $argv[1] ?? dirname(__DIR__, 3).'/frontend/docs/reviews/dossiers-phase-four/full-history.xlsx';
$book = IOFactory::load($path);
try {
    DossierWorkbookAssertions::check($book, false);
    $values = [];
    foreach ($book->getAllSheets() as $sheet) {
        foreach ($sheet->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            if ($cell->getDataType() === 'f') {
                throw new RuntimeException('Unexpected formula in history workbook');
            }
            $values[] = (string) $cell->getValue();
        }
    }
    $text = implode("\n", $values);
    foreach (['التسلسل الزمني للزيارات', 'خدمة أضيفت بالخطأ', 'حالة الواقعة: ملغاة', 'الأدوية الموصوفة', 'الأدوية المصروفة'] as $expected) {
        if (! str_contains($text, $expected)) {
            throw new RuntimeException('Missing historical report content: '.$expected);
        }
    }
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Invalid XLSX archive');
    }
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $bytes = $zip->getFromIndex($i);
            if (str_contains($name, 'embeddings/') || preg_match('#(?:files|staging)/[0-9a-f-]{36}#i', $bytes)) {
                throw new RuntimeException('Private payload in workbook');
            }
            if (str_starts_with($name, 'xl/media/') && $bytes !== file_get_contents(dirname(__DIR__, 2).'/resources/reports/logo-ar-color.png')) {
                throw new RuntimeException('Unexpected embedded image');
            }
        }
    } finally {
        $zip->close();
    }
    echo $book->getSheetCount()." worksheets passed: reopened print settings, RTL/Cairo, safe cell types, void reasons, prescription/dispensing separation and no private payload.\n";
} finally {
    $book->disconnectWorksheets();
}
