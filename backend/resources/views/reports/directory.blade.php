<!doctype html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><style>
body { font-family: cairo; color: #233f3c; line-height: 1.45; font-size: 10.5pt; }
h1 { color: #155c56; font-size: 21pt; margin: 0 0 3mm; }
h2 { color: #155c56; font-size: 14pt; margin: 5mm 0 2mm; page-break-after: avoid; }
p { margin: 0 0 2mm; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 1.7mm 1.6mm; text-align: right; vertical-align: top; }
th { background: #155c56; color: #fff; font-weight: bold; font-size: 10pt; }
.data td { border-bottom: .2mm solid #dce6e3; font-size: 10.5pt; }
.stripe { background: #f3f7f6; }
.brand td { padding: 0 0 3mm; border-bottom: .65mm solid #155c56; vertical-align: middle; }
.hospital { font-weight: bold; font-size: 12pt; color: #155c56; }
.caption { font-size: 9pt; color: #46615b; }
.metadata { margin: 2mm 0; }
.metadata td { width: 50%; font-size: 9.5pt; padding: 1mm 0; }
.label { font-size: 9pt; color: #46615b; }
.filters { font-size: 9pt; padding: 1.5mm 0 3mm; color: #314f49; }
.identity { background: #edf4f1; padding: 3mm 4mm; border-right: 1mm solid #155c56; }
.identity h2 { margin: 0 0 1mm; font-size: 16pt; }
.facts { margin: 2mm 0; }
.facts td { width: 50%; padding: 2mm 3mm; border-bottom: .2mm solid #dce6e3; }
.metric { font-size: 15pt; font-weight: bold; color: #155c56; }
.fulltext { white-space: pre-wrap; overflow-wrap: break-word; line-height: 1.4; }
.ltr { direction: ltr; unicode-bidi: embed; text-align: left; }
.center { text-align: center; }
.reference { color: #155c56; font-size: 9pt; page-break-after: avoid; }
.note { font-size: 9pt; color: #36564e; padding: 2mm 0; border-bottom: .2mm solid #dce6e3; }
</style></head><body>
<table class="brand"><tr><td width="76%"><div class="hospital">مشفى محمد بن زايد الإماراتي</div><h1>{{ $metadata['title'] }}</h1><span class="caption">الدليل الطبي · تقرير إداري</span></td><td width="24%" align="left"><img src="var:hospitalLogo" width="132" alt="هوية المشفى"></td></tr></table>
<table class="metadata"><tr><td><span class="label">رقم التقرير</span> &nbsp; <span dir="ltr">{{ $metadata['number'] }}</span></td><td><span class="label">الإصدار</span> &nbsp; <span dir="ltr">{{ $metadata['issued_at'] }}</span> · {{ $metadata['timezone'] }}</td></tr><tr><td><span class="label">المنشأة</span> &nbsp; {{ $metadata['facility'] }}</td><td><span class="label">أصدره</span> &nbsp; {{ $metadata['issuer'] }}</td></tr></table>
<div class="filters"><strong>نطاق التقرير:</strong> {{ $metadata['filters'] }}</div>
@if ($detail)
  @foreach ($rows as $row)
    <div class="identity"><h2>{{ $row['name'] ?? $row['name_ar'] }}</h2><span class="label">الكود</span> &nbsp; <span dir="ltr">{{ $row['code'] }}</span></div>
    @php($facts = array_filter($row['details'], fn($label) => !str_starts_with($label, 'عدد '), ARRAY_FILTER_USE_KEY))
    @php($metrics = array_filter($row['details'], fn($label) => str_starts_with($label, 'عدد '), ARRAY_FILTER_USE_KEY))
    <table class="facts">@foreach (array_merge(array_chunk($facts, 2, true), array_chunk($metrics, 2, true)) as $pair)<tr>@foreach ($pair as $label => $value)<td><strong class="label">{{ $label }}</strong><br><span class="{{ str_starts_with($label, 'عدد ') ? 'metric' : '' }}" dir="auto">{{ $value === null || $value === '' ? '—' : $value }}</span></td>@endforeach @if(count($pair) === 1)<td></td>@endif</tr>@endforeach</table>
    <h2>{{ array_key_exists('name_ar', $row) ? 'توصيف العيادة' : 'التوصيف المهني' }}</h2><div class="fulltext">{{ $row['description'] ?: 'لا يوجد توصيف مسجل.' }}</div>
    <h2>{{ $linkTitle }}</h2><table class="data" autosize="1"><thead><tr><th width="23%">الكود</th><th width="52%">الاسم</th><th width="25%">بداية الارتباط</th></tr></thead><tbody>
    @forelse ($row['links'] as $i => $link)<tr class="{{ $i % 2 ? 'stripe' : '' }}"><td class="ltr">{{ $link['code'] }}</td><td>{{ $link['name'] }}</td><td class="ltr">{{ $link['starts_on'] }}</td></tr>
    @empty<tr><td colspan="3">لا توجد ارتباطات حالية في المنشأة المحددة.</td></tr>@endforelse
    </tbody></table>
  @endforeach
  <p class="note"><strong>احتساب المرضى:</strong> {{ $metadata['definition'] }}</p>
@else
  @php($appendix = [])
  @php($widths = \App\Services\Directory\ReportLayout::widths($columns))
  <table class="data" autosize="1"><thead><tr>@foreach ($columns as $key)<th class="{{ \App\Services\Directory\ReportLayout::numeric($key) ? 'center' : '' }}" width="{{ round($widths[$key] / array_sum($widths) * 100, 2) }}%">{{ $labels[$key] }}</th>@endforeach</tr></thead><tbody>
  @forelse ($rows as $index => $row)<tr class="{{ $index % 2 ? 'stripe' : '' }}">
    @foreach ($columns as $key)
      @php($value = $key === 'number' ? $index + 1 : ($row[$key] ?? '—'))
      @if (is_string($value) && \App\Services\Directory\ReportLayout::lines($value, $widths[$key] - 3.2) > 12)
        @php($appendix[] = ['code' => $row['code'], 'name' => $row['name'] ?? $row['name_ar'], 'label' => $labels[$key], 'text' => $value])
        <td>{{ mb_substr($value, 0, 90) }}…<br><span class="reference">النص الكامل · ملحق {{ count($appendix) }}</span></td>
      @else<td class="{{ \App\Services\Directory\ReportLayout::numeric($key) ? 'center' : ($key === 'code' ? 'ltr' : '') }}">{{ $value }}</td>@endif
    @endforeach</tr>
  @empty<tr><td colspan="{{ count($columns) }}">لا توجد نتائج مطابقة.</td></tr>@endforelse
  </tbody></table>
  <p class="note"><strong>احتساب المرضى:</strong> {{ $metadata['definition'] }}</p>
  @if ($appendix)
    @foreach ($appendix as $index => $item)<h2>النصوص الكاملة · ملحق {{ $index + 1 }} · {{ $item['label'] }}</h2><p class="reference">{{ $item['name'] }} · <span dir="ltr">{{ $item['code'] }}</span></p><div class="fulltext">{{ $item['text'] }}</div>@endforeach
  @endif
@endif
</body></html>
