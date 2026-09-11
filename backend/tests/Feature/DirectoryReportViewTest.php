<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class DirectoryReportViewTest extends TestCase
{
    public function test_detail_report_keeps_zero_counts_and_escapes_professional_text(): void
    {
        $html = view('reports.directory', [
            'metadata' => ['title' => 'تفاصيل طبيب', 'facility' => 'منشأة اختبارية', 'number' => 'DR-TEST', 'issuer' => 'اختبار', 'issued_at' => '2026-09-11', 'timezone' => 'Asia/Damascus', 'filters' => 'تفاصيل', 'definition' => 'تعريف المؤشر'],
            'detail' => true, 'linkTitle' => 'العيادات',
            'rows' => [['name' => '<script>alert(1)</script>', 'code' => '0001', 'description' => '<img src="https://invalid.test/private">', 'details' => ['عدد المرضى' => 0, 'الهاتف' => null], 'links' => []]],
        ])->render();
        $dom = new DOMDocument;
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $this->assertSame('0', $xpath->query('//td[strong="عدد المرضى"]/span')->item(0)->textContent);
        $this->assertSame('—', $xpath->query('//td[strong="الهاتف"]/span')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//script | //img[not(starts-with(@src,"var:"))]')->length);
        $this->assertStringContainsString('<script>alert(1)</script>', $dom->textContent);
        $this->assertStringContainsString('<img src="https://invalid.test/private">', $dom->textContent);
    }
}
