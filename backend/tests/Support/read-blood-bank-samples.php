<?php

// Read-only validation of synthetic files produced by the real Next integration.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$root = realpath(dirname(__DIR__, 3).'/frontend/.superdesign/blood-bank-reports');
if (! $root) {
    throw new RuntimeException('Run blood-bank-reports-live.test.mjs first.');
}
foreach (['list', 'donor', 'recipient', 'donation'] as $name) {
    $book = IOFactory::load($root.'/'.$name.'.xlsx');
    foreach ($book->getAllSheets() as $sheet) {
        if (! $sheet->getRightToLeft() || $sheet->getPageSetup()->getFitToHeight() !== 0) {
            throw new RuntimeException('Incorrect print direction/scaling.');
        }
        foreach ($sheet->getRowDimensions() as $row) {
            if ($row->getRowHeight() > 409) {
                throw new RuntimeException('Excel print row overflow.');
            }
        }
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            if ($sheet->getCell($coordinate)->getDataType() === 'f') {
                throw new RuntimeException('Report text became a formula.');
            }
        }
        echo $name.': '.$sheet->getTitle().', '.$sheet->getHighestRow()." rows, RTL; print heights and types checked.\n";
    }
    if ($name === 'list') {
        $sheet = $book->getActiveSheet();
        if ($sheet->getHighestRow() !== 40 || $sheet->getCell('D9')->getValue() !== '0012345678' || $sheet->getCell('D9')->getDataType() !== 's') {
            throw new RuntimeException('Expected all 32 matching files, exact text phone with leading zeroes.');
        }
    }
    if ($name === 'donor' || $name === 'donation') {
        $sheet = $book->getSheetByName('التبرعات');
        if ($sheet->getCell('B9')->getDataType() !== 'n' || $sheet->getCell('D9')->getValue() !== 1.25 || $sheet->getCell('A9')->getDataType() !== 's') {
            throw new RuntimeException('Donation date, quantity or code lost its type.');
        }
    }
    $book->disconnectWorksheets();
}
