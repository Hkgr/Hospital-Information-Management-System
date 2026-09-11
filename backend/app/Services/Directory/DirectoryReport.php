<?php

namespace App\Services\Directory;

use Mpdf\Config\ConfigVariables;
use Mpdf\Container\ContainerInterface;
use Mpdf\Http\ClientInterface;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

class DirectoryReport
{
    public function response(array $document, string $format): Response
    {
        $bytes = $format === 'xlsx' ? $this->xlsx($document) : $this->pdf($document);

        return response($bytes, 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document['metadata']['number'].'.'.$format.'"',
            'X-Report-Number' => $document['metadata']['number'], 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function pdf(array $document): string
    {
        $container = new class implements ContainerInterface
        {
            public function has($id)
            {
                return $id === 'httpClient';
            }

            public function get($id)
            {
                return new class implements ClientInterface
                {
                    public function sendRequest(RequestInterface $request)
                    {
                        throw new \RuntimeException('External report assets are forbidden.');
                    }
                };
            }
        };
        // Only these two static fonts are registered. Disable language-based fallback.
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => $document['detail'] || count($document['columns']) <= 4 ? 'A4' : 'A4-L',
            'fontDir' => array_merge((new ConfigVariables)->getDefaults()['fontDir'], [resource_path('fonts/cairo')]),
            'fontdata' => ['cairo' => ['R' => 'Cairo-Regular.ttf', 'B' => 'Cairo-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75]],
            'default_font' => 'cairo', 'default_font_size' => 10, 'autoScriptToLang' => false, 'autoLangToFont' => false,
            'margin_top' => 28, 'margin_bottom' => 19, 'margin_left' => 12, 'margin_right' => 12,
            'tempDir' => storage_path('framework/cache/directory-pdf')], $container);
        try {
            $pdf->shrink_tables_to_fit = 1;
            $pdf->SetDirectionality('rtl');
            $pdf->SetTitle($document['metadata']['title']);
            $pdf->SetAuthor($document['metadata']['issuer']);
            $pdf->imageVars['hospitalLogo'] = file_get_contents(resource_path('reports/logo-ar-color.png'));
            $pdf->imageVars['medicalLine'] = file_get_contents(resource_path('reports/medical-line.svg'));
            $pdf->SetHTMLHeader('<table width="100%" style="border-bottom:1px solid #dbe8e7;font-family:cairo"><tr><td><img src="var:hospitalLogo" width="140"></td><td align="left"><img src="var:medicalLine" width="125"></td></tr></table>');
            $pdf->SetHTMLFooter('<div style="border-top:1px solid #dbe8e7;color:#58706f;text-align:center;font-family:cairo;font-size:9pt"><span dir="ltr">'.e($document['metadata']['number']).'</span> · {PAGENO} / {nbpg}</div>');
            $pdf->WriteHTML(view('reports.directory', $document)->render());

            return $pdf->Output('', 'S');
        } finally {
            unset($pdf);
            gc_collect_cycles();
        }
    }

    private function xlsx(array $document): string
    {
        $book = new Spreadsheet;
        $meta = $document['metadata'];
        $book->getDefaultStyle()->getFont()->setName('Cairo')->setSize(11);
        $book->getProperties()->setCreator($meta['issuer'])->setTitle($meta['title'])->setSubject($meta['number']);
        $sheet = $book->getActiveSheet()->setTitle($meta['title'])->setRightToLeft(true);
        $columns = $document['columns'];
        $count = count($columns);
        $drawing = new Drawing;
        $drawing->setPath(resource_path('reports/logo-ar-color.png'))->setName('هوية المشفى')->setHeight(62)->setCoordinates('A1')->setOffsetX(8)->setOffsetY(6)->setWorksheet($sheet);
        $sheet->getRowDimension(1)->setRowHeight(58);
        $lines = [$meta['title'].' · '.$meta['number'], 'المنشأة: '.$meta['facility'], 'أصدره: '.$meta['issuer'].' | '.$meta['issued_at'].' | '.$meta['timezone'], 'الفلاتر: '.$meta['filters'], $meta['definition']];
        foreach ($lines as $index => $line) {
            $sheet->setCellValueExplicit([1, $index + 2], $line, DataType::TYPE_STRING);
            if ($count > 1) {
                $sheet->mergeCells([1, $index + 2, $count, $index + 2]);
            }
            $sheet->getRowDimension($index + 2)->setRowHeight($index >= 3 ? 48 : 30);
        }
        $sheet->getStyle([1, 2, $count, 2])->getFont()->setBold(true)->setSize(16)->getColor()->setARGB('FF176B68');
        $appendix = [];
        foreach ($columns as $col => $key) {
            $sheet->setCellValueExplicit([$col + 1, 8], $document['labels'][$key], DataType::TYPE_STRING);
            $width = match ($key) {
                'number' => 5, 'code' => 16, 'doctor_count', 'clinic_count', 'patient_count', 'is_active' => 12,
                'description', 'doctors', 'clinics' => 27, 'specialties' => 22, default => 20
            };
            $sheet->getColumnDimensionByColumn($col + 1)->setWidth($count <= 4 ? max($width, 95 / $count) : $width);
        }
        foreach ($document['rows'] as $index => $row) {
            $height = 34;
            foreach ($columns as $col => $key) {
                $value = $key === 'number' ? $index + 1 : ($row[$key] ?? '');
                if (is_string($value) && mb_strlen($value) > 350) {
                    $appendix[] = ['code' => $row['code'], 'label' => $document['labels'][$key], 'text' => $value];
                    $value = mb_substr($value, 0, 100).'… [النص الكامل: ملحق '.count($appendix).']';
                }
                $numeric = in_array($key, ['number', 'doctor_count', 'clinic_count', 'patient_count'], true);
                $sheet->setCellValueExplicit([$col + 1, $index + 9], $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                $width = $sheet->getColumnDimensionByColumn($col + 1)->getWidth();
                $height = max($height, (ceil(mb_strlen((string) $value) / max(8, $width - 4)) + substr_count((string) $value, "\n")) * 21 + 12);
            }
            $sheet->getRowDimension($index + 9)->setRowHeight(min(390, $height));
            if ($index % 2 === 1) {
                $sheet->getStyle([1, $index + 9, $count, $index + 9])->getFill()->setFillType('solid')->getStartColor()->setARGB('FFF1F7F7');
            }
        }
        $last = max(8, 8 + count($document['rows']));
        $sheet->getStyle([1, 2, $count, $last])->getAlignment()->setWrapText(true)->setVertical('center')->setHorizontal('right');
        foreach ($columns as $col => $key) {
            if (in_array($key, ['number', 'doctor_count', 'clinic_count', 'patient_count', 'is_active'], true)) {
                $sheet->getStyle([$col + 1, 8, $col + 1, $last])->getAlignment()->setHorizontal('center');
            }
        }
        $sheet->getStyle([1, 8, $count, 8])->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle([1, 8, $count, 8])->getFill()->setFillType('solid')->getStartColor()->setARGB('FF176B68');
        $sheet->getRowDimension(8)->setRowHeight(42);
        $sheet->getStyle([1, 8, $count, $last])->getBorders()->getAllBorders()->setBorderStyle('hair')->getColor()->setARGB('FFDCE8E7');
        $sheet->freezePane('A9')->setAutoFilter([1, 8, $count, $last]);
        $sheet->getPageSetup()->setOrientation($count > 4 ? 'landscape' : 'portrait')->setPaperSize(9)->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(8, 8)->setPrintArea('A1:'.Coordinate::stringFromColumnIndex($count).$last);
        $sheet->getHeaderFooter()->setOddFooter('&"Cairo,Regular"&C'.$meta['number'].' | &P / &N');
        if ($appendix) {
            $extra = $book->createSheet()->setTitle('النصوص الكاملة')->setRightToLeft(true);
            $extra->getColumnDimension('A')->setWidth(15);
            $extra->getColumnDimension('B')->setWidth(24);
            $extra->getColumnDimension('C')->setWidth(70);
            foreach (['الملحق', 'الكود / الحقل', 'النص الكامل · متابعة بالترتيب'] as $i => $label) {
                $extra->setCellValueExplicit([$i + 1, 1], $label, DataType::TYPE_STRING);
            }
            $line = 2;
            foreach ($appendix as $index => $item) {
                foreach (mb_str_split($item['text'], 200) as $chunk) {
                    foreach ([(string) ($index + 1), $item['code'].' · '.$item['label'], $chunk] as $i => $value) {
                        $extra->setCellValueExplicit([$i + 1, $line], $value, DataType::TYPE_STRING);
                    }
                    $extra->getRowDimension($line++)->setRowHeight(105);
                }
            }
            $extra->getStyle('A1:C'.$line)->getAlignment()->setWrapText(true)->setVertical('top')->setHorizontal('right');
            $extra->getStyle('A1:C1')->getFont()->setBold(true);
            $extra->getStyle('A1:C1')->getFont()->getColor()->setARGB('FFFFFFFF');
            $extra->getStyle('A1:C1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF176B68');
            $extra->getRowDimension(1)->setRowHeight(30);
            $extra->freezePane('C2');
            $extra->getPageSetup()->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 1)->setPrintArea('A1:C'.($line - 1));
            $extra->getHeaderFooter()->setOddFooter('&"Cairo,Regular"&C'.$meta['number'].' | &P / &N');
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
            unset($sheet, $drawing, $book);
            gc_collect_cycles();
        }
    }
}
