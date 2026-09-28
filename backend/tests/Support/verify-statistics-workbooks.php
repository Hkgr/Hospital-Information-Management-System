<?php

use PhpOffice\PhpSpreadsheet\IOFactory;

require dirname(__DIR__, 2).'/vendor/autoload.php';
// Reads only the synthetic reports from the real local integration suite.
// Does not bootstrap Laravel, read credentials, or connect to any database.
$check = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
foreach (['report', 'long-report'] as $name) {
    $path = dirname(__DIR__, 3).'/frontend/test-results/statistics/'.$name.'.xlsx';
    $book = IOFactory::load($path);
    $sheet = $book->getActiveSheet();
    $check($book->getSheetCount() === 1 && $sheet->getSheetState() === 'visible', 'No hidden/raw sheets');
    $check($sheet->getRightToLeft() && $book->getDefaultStyle()->getFont()->getName() === 'Cairo', 'RTL Cairo');
    $print = $sheet->getPageSetup();
    $check($print->getPaperSize() === 9 && $print->getOrientation() === 'landscape', 'A4 landscape');
    $check($print->getFitToWidth() === 1 && $print->getFitToHeight() === 0, 'Width fit without forced page height');
    $check($print->getPrintArea() === 'A1:F'.$sheet->getHighestRow(), 'Explicit complete print area');
    $check($print->getRowsToRepeatAtTop() === [8, 8] && $sheet->getFreezePane() === 'A9', 'Repeated/frozen headers');
    $check($sheet->getAutoFilter()->getRange() === 'A8:F'.$sheet->getHighestRow(), 'Complete filter');
    foreach ($sheet->getRowIterator() as $row) {
        $height = $sheet->getRowDimension($row->getRowIndex())->getRowHeight();
        $check($height <= 409.5, 'Excel row-height limit');
        foreach ($row->getCellIterator() as $cell) {
            $check($cell->getDataType() !== 'f', 'No executable formulas');
            $value = $cell->getValue();
            if ($value === null) {
                continue;
            }
            $check(is_int($value) || is_float($value) ? $cell->getDataType() === 'n' : $cell->getDataType() === 's', 'Safe scalar types');
            $check(! preg_match('/SECRET|0900999000|patient_id|dossier_id/', (string) $value), 'No private fixture content');
        }
    }
    echo $name.': reopened; RTL, Cairo, complete print area, headers, types, formulas and '.$sheet->getHighestRow()." rows verified.\n";
    $book->disconnectWorksheets();
}
