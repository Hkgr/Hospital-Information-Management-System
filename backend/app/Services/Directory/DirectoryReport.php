<?php

namespace App\Services\Directory;

use Mpdf\Config\ConfigVariables;
use Mpdf\Container\ContainerInterface;
use Mpdf\Http\ClientInterface;
use Mpdf\Mpdf;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

class DirectoryReport
{
    public function response(array $document, string $format): Response
    {
        $bytes = $format === 'xlsx' ? app(DirectorySpreadsheet::class)->render($document) : $this->pdf($document);

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
            'margin_top' => 20, 'margin_bottom' => 17, 'margin_left' => 12, 'margin_right' => 12,
            'tempDir' => storage_path('framework/cache/directory-pdf')], $container);
        try {
            $pdf->shrink_tables_to_fit = 1;
            $pdf->SetDirectionality('rtl');
            $pdf->SetTitle($document['metadata']['title']);
            $pdf->SetAuthor($document['metadata']['issuer']);
            $pdf->imageVars['hospitalLogo'] = file_get_contents(resource_path('reports/logo-ar-color.png'));
            $pdf->imageVars['medicalLine'] = file_get_contents(resource_path('reports/medical-line.svg'));
            $pdf->DefHTMLHeaderByName('continuation', '<table width="100%" style="border-bottom:.5pt solid #bed1cb;font-family:cairo;color:#155c56;font-size:10pt"><tr><td><b>'.e($document['metadata']['title']).'</b> · مشفى محمد بن زايد الإماراتي</td><td align="left"><img src="var:medicalLine" width="95"></td></tr></table>');
            $pdf->WriteHTML('<sethtmlpageheader name="continuation" value="on" show-this-page="0" />');
            $pdf->SetHTMLFooter('<div style="border-top:.5pt solid #bed1cb;color:#36564e;text-align:center;font-family:cairo;font-size:9pt"><span dir="ltr">'.e($document['metadata']['number']).'</span> &nbsp; · &nbsp; الصفحة {PAGENO} من {nbpg}</div>');
            $pdf->WriteHTML(view('reports.directory', $document)->render());

            return $pdf->Output('', 'S');
        } finally {
            unset($pdf);
            gc_collect_cycles();
        }
    }
}
