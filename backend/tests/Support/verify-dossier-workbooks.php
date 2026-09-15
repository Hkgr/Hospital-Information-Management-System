<?php

// Read-only verification of the actual review artifacts; no Laravel boot or DB.
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DossierWorkbookAssertions;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$directory = realpath($argv[1] ?? dirname(__DIR__, 3).'/frontend/docs/reviews/dossiers-phase-three');
if (! $directory) {
    throw new RuntimeException('Supply an existing directory containing list.xlsx, dossier.xlsx and visit.xlsx.');
}
foreach (['list', 'dossier', 'visit'] as $kind) {
    $path = $directory.DIRECTORY_SEPARATOR.$kind.'.xlsx';
    if (IOFactory::identify($path) !== 'Xlsx') {
        throw new RuntimeException('Expected an XLSX workbook: '.$kind);
    }
    $book = IOFactory::load($path);
    try {
        DossierWorkbookAssertions::check($book, $kind === 'list');
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Invalid workbook archive');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_contains($name, 'embeddings/') || preg_match('#(?:files|staging)/[0-9a-f-]{36}#i', $zip->getFromIndex($i))) {
                    throw new RuntimeException('Private payload detected in '.$kind);
                }
                if (str_starts_with($name, 'xl/media/') && $zip->getFromIndex($i) !== file_get_contents(dirname(__DIR__, 2).'/resources/reports/logo-ar-color.png')) {
                    throw new RuntimeException('Unexpected embedded media in '.$kind);
                }
            }
        } finally {
            $zip->close();
        }
        echo $kind.': '.$book->getSheetCount()." sheets passed reopened print, RTL/font, cell-type/formula and private-payload assertions.\n";
    } finally {
        $book->disconnectWorksheets();
    }
}
