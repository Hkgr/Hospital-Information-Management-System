<?php

namespace App\Services\Dossiers\Imports;

use Generator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use XMLReader;
use ZipArchive;

/** Forward-only bounded XLSX values reader; never evaluates formulas or loads cell objects. */
class ImportXmlRows
{
    private ZipArchive $zip;

    private array $strings = [];

    public array $sheets = [];

    public bool $calendar1904 = false;

    public function __construct(private string $path)
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($path) !== true) {
            $this->invalid();
        }
        $workbook = simplexml_load_string($this->zip->getFromName('xl/workbook.xml'), options: LIBXML_NONET);
        $rels = simplexml_load_string($this->zip->getFromName('xl/_rels/workbook.xml.rels'), options: LIBXML_NONET);
        if (! $workbook || ! $rels) {
            $this->invalid();
        }
        $links = [];
        foreach ($rels->children() as $rel) {
            $target = (string) $rel['Target'];
            if (str_contains($target, '..') || str_contains($target, '\\')) {
                $this->invalid();
            }
            $links[(string) $rel['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        }
        foreach ($workbook->sheets->sheet as $sheet) {
            if (isset($this->sheets[(string) $sheet['name']])) {
                $this->invalid();
            }
            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            $this->sheets[(string) $sheet['name']] = $links[$id] ?? '';
        }
        $this->calendar1904 = in_array((string) $workbook->workbookPr['date1904'], ['1', 'true']);
        if ($this->zip->locateName('xl/sharedStrings.xml') !== false) {
            $xml = $this->reader('xl/sharedStrings.xml');
            try {
                $bytes = 0;
                while ($xml->read()) {
                    if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'si') {
                        $value = $this->text($xml->readOuterXml());
                        $bytes += strlen($value);
                        if (count($this->strings) >= 300000 || $bytes > 25000000 || mb_strlen($value) > 20000) {
                            $this->invalid();
                        }
                        $this->strings[] = $value;
                    }
                }
            } finally {
                $xml->close();
            }
        }
    }

    public function rows(string $sheet): Generator
    {
        $xml = $this->reader($this->sheets[$sheet] ?? '');
        try {
            $count = 0;
            $last = 0;
            while ($xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'row') {
                    continue;
                }
                $n = (int) $xml->getAttribute('r');
                if (++$count > 30002 || $n <= $last || $n > 30002) {
                    $this->invalid();
                }
                $last = $n;
                $rowXml = $xml->readOuterXml();
                if (strlen($rowXml) > 1000000) {
                    $this->invalid();
                }
                $row = simplexml_load_string($rowXml, options: LIBXML_NONET);
                if (! $row) {
                    $this->invalid();
                }
                $values = [];
                foreach ($row->children() as $cell) {
                    if ($cell->getName() !== 'c') {
                        continue;
                    }
                    if (isset($cell->f)) {
                        $this->invalid('لا تُقبل الصيغ في ملف الاستيراد.');
                    }
                    $address = (string) $cell['r'];
                    if (! preg_match('/\A([A-Z]{1,2})([0-9]+)\z/', $address, $m) || (int) $m[2] !== $n) {
                        $this->invalid();
                    }
                    $col = Coordinate::columnIndexFromString($m[1]);
                    if ($col > 40 || isset($values[$col])) {
                        $this->invalid();
                    }
                    $type = (string) $cell['t'];
                    $raw = isset($cell->v) ? (string) $cell->v : null;
                    $value = match ($type) {
                        's' => $raw !== null && ctype_digit($raw) && array_key_exists((int) $raw, $this->strings) ? $this->strings[(int) $raw] : $this->invalid(),
                        'inlineStr' => $this->text($cell->asXML()),
                        'str' => $raw,
                        '', 'n', 'b' => $raw === null ? null : (is_numeric($raw) ? $raw + 0 : $this->invalid()),
                        default => $this->invalid(),
                    };
                    if ($value !== null && mb_strlen((string) $value) > 20000) {
                        $this->invalid();
                    }
                    $values[$col] = $value;
                }
                yield $n => $values;
            }
        } finally {
            $xml->close();
        }
    }

    private function text(string $xml): string
    {
        $el = simplexml_load_string($xml, options: LIBXML_NONET);
        if (! $el) {
            $this->invalid();
        }

        return implode('', array_map(fn ($t) => (string) $t, $el->xpath('//*[local-name()="t"]')));
    }

    private function reader(string $entry): XMLReader
    {
        if ($entry === '' || $this->zip->locateName($entry) === false) {
            $this->invalid();
        }
        $reader = new XMLReader;
        if (! $reader->open('zip://'.$this->path.'#'.$entry, null, LIBXML_NONET)) {
            $this->invalid();
        }

        return $reader;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    private function invalid(string $message = 'بنية XLSX تالفة أو غير مدعومة؛ استخدم القالب الحالي دون صيغ أو خلايا تتجاوز الحدود.'): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
