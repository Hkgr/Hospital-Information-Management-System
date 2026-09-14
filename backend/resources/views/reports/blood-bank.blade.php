<!doctype html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><style>
body { font-family: cairo; color: #233f3c; font-size: 10.5pt; line-height: 1.45; }
h1 { color: #155c56; font-size: 21pt; margin: 0 0 3mm; }
h2 { color: #155c56; font-size: 14pt; margin: 5mm 0 2mm; page-break-after: avoid; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
td, th { padding: 1.7mm 1.6mm; text-align: right; vertical-align: top; }
th { background: #155c56; color: white; font-size: 10pt; }
.data td { border-bottom: .2mm solid #dce6e3; }
.stripe { background: #f3f7f6; }
.brand td { vertical-align: middle; border-bottom: .65mm solid #155c56; }
.meta, .note { color: #36564e; font-size: 9pt; margin: 2mm 0; }
.ltr { direction: ltr; unicode-bidi: embed; text-align: left; }
.fulltext { white-space: pre-wrap; }
.identity { background: #edf4f1; padding: 3mm 4mm; border-right: 1mm solid #155c56; margin: 3mm 0; }
</style></head><body>
<table class="brand"><tr><td width="76%"><strong>مشفى محمد بن زايد الإماراتي</strong><h1>{{ $metadata['title'] }}</h1>بنك الدم · تقرير سجلات</td><td width="24%"><img src="var:hospitalLogo" width="132" alt="هوية المشفى"></td></tr></table>
<p class="meta">المنشأة: {{ $metadata['facility'] }} · أصدره: {{ $metadata['issuer'] }}<br>رقم التقرير: <span dir="ltr">{{ $metadata['number'] }}</span><br>الإصدار: <span dir="ltr">{{ $metadata['issued_at'] }}</span> · {{ $metadata['timezone'] }}</p>
<p class="meta">{{ $metadata['filters'] }}</p>
@if ($identity)<div class="identity"><strong>{{ $identity['name'] }}</strong><br><span dir="ltr">{{ $identity['code'] }}</span></div>@endif
@foreach ($sections as $section)
<h2>{{ $section['title'] }}</h2><p class="note">{{ $section['note'] }}</p>
@php($widths = \App\Services\BloodBank\BloodBankReports::widths($section['labels']))
@php($totalWidth = array_sum($widths))
@php($scale = $detail ? 186 / $totalWidth : 1)
<table class="data" autosize="1"><thead><tr>@foreach ($section['labels'] as $key => $label)<th width="{{ round($widths[$key] / $totalWidth * 100, 2) }}%">{{ $label }}</th>@endforeach</tr></thead><tbody>
@forelse ($section['rows'] as $index => $row)
  @php($parts = [])
  @foreach ($section['labels'] as $key => $label)
    @php($parts[$key] = \App\Services\Directory\ReportLayout::printParts((string) ($row[$key] ?? 'غير مسجل'), $widths[$key] * $scale - 3.2))
  @endforeach
  @for ($part = 0; $part < max(array_map('count', $parts)); $part++)
  <tr class="{{ $index % 2 ? 'stripe' : '' }}">@foreach ($section['labels'] as $key => $label)<td class="fulltext {{ in_array($key, ['code','phone','blood','donated_on','units','analyte']) ? 'ltr' : '' }}">{{ $parts[$key][$part] ?? '' }}</td>@endforeach</tr>
  @endfor
@empty<tr><td colspan="{{ count($section['labels']) }}">{{ $section['empty'] }}</td></tr>@endforelse
</tbody></table>
@endforeach
<p class="note">يعرض هذا التقرير السجلات المحفوظة؛ لا يمثل اعتمادًا طبيًا أو شهادة أهلية للتبرع.</p>
</body></html>
