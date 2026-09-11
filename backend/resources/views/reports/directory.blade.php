<!doctype html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><style>
body { font-family: cairo; color: #244843; line-height: 1.7; font-size: 10pt; }
h1 { color: #176b68; font-size: 21pt; margin: 8px 0; } h2 { color: #176b68; font-size: 14pt; margin-top: 18px; }
.meta { color: #607772; font-size: 9pt; } .intro { background: #f1f7f6; padding: 12px; margin-bottom: 14px; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; } th { background: #176b68; color: white; font-weight: bold; }
th, td { padding: 7px 6px; border-bottom: 1px solid #dce8e5; text-align: right; vertical-align: top; }
td { font-size: 10pt; } .stripe { background: #f3f8f7; } .fulltext { white-space: pre-wrap; overflow-wrap: break-word; }
.ltr { direction: ltr; unicode-bidi: embed; text-align: left; } .reference { color: #176b68; font-size: 9pt; }
</style></head><body>
<h1>{{ $metadata['title'] }}</h1>
<div class="intro"><strong>{{ $metadata['facility'] }}</strong><br>
<span class="meta">رقم التقرير: <span class="ltr">{{ $metadata['number'] }}</span> · النوع: {{ $detail ? 'تفاصيل' : 'قائمة' }}<br>
أصدره: {{ $metadata['issuer'] }} · <span class="ltr">{{ $metadata['issued_at'] }} | {{ $metadata['timezone'] }}</span><br>
الفلاتر: {{ $metadata['filters'] }}</span></div>
<p class="meta">{{ $metadata['definition'] }}</p>
@if ($detail)
  @foreach ($rows as $row)
    <h2>{{ $row['name'] ?? $row['name_ar'] }} · <span class="ltr">{{ $row['code'] }}</span></h2>
    @foreach ($row['details'] as $label => $value)<p><strong>{{ $label }}:</strong> <span dir="auto">{{ $value === null || $value === '' ? '—' : $value }}</span></p>@endforeach
    <h2>التوصيف المهني</h2><div class="fulltext">{{ $row['description'] ?: 'لا يوجد توصيف مسجل.' }}</div>
    <h2>{{ $linkTitle }}</h2><table><thead><tr><th width="23%">الكود</th><th width="52%">الاسم</th><th width="25%">بداية الارتباط</th></tr></thead><tbody>
    @forelse ($row['links'] as $i => $link)<tr class="{{ $i % 2 ? 'stripe' : '' }}"><td class="ltr">{{ $link['code'] }}</td><td>{{ $link['name'] }}</td><td class="ltr">{{ $link['starts_on'] }}</td></tr>
    @empty<tr><td colspan="3">لا توجد ارتباطات حالية في المنشأة المحددة.</td></tr>@endforelse
    </tbody></table>
  @endforeach
@else
  @php($appendix = [])
  @php($weights = array_map(fn($key) => match ($key) { 'number' => 6, 'clinic_count', 'doctor_count', 'patient_count' => 18, 'code' => 20, 'is_active' => 13, 'description', 'clinics', 'doctors' => 34, default => 30 }, $columns))
  <table autosize="1"><thead><tr>@foreach ($columns as $i => $key)<th width="{{ round($weights[$i] / array_sum($weights) * 100, 2) }}%">{{ $labels[$key] }}</th>@endforeach</tr></thead><tbody>
  @forelse ($rows as $index => $row)<tr class="{{ $index % 2 ? 'stripe' : '' }}">
    @foreach ($columns as $key)
      @php($value = $key === 'number' ? $index + 1 : ($row[$key] ?? '—'))
      @if (is_string($value) && mb_strlen($value) > 180)
        @php($appendix[] = ['code' => $row['code'], 'label' => $labels[$key], 'text' => $value])
        <td>{{ mb_substr($value, 0, 65) }}…<br><span class="reference">النص الكامل في الملحق {{ count($appendix) }}</span></td>
      @else<td class="{{ in_array($key, ['code','number','patient_count','clinic_count','doctor_count']) ? 'ltr' : '' }}">{{ $value }}</td>@endif
    @endforeach</tr>
  @empty<tr><td colspan="{{ count($columns) }}">لا توجد نتائج مطابقة.</td></tr>@endforelse
  </tbody></table>
  @if ($appendix)<pagebreak /><h1>ملحق النصوص الكاملة</h1>
    @foreach ($appendix as $index => $item)<h2>ملحق {{ $index + 1 }} · <span class="ltr">{{ $item['code'] }}</span> · {{ $item['label'] }}</h2><div class="fulltext">{{ $item['text'] }}</div>@endforeach
  @endif
@endif
</body></html>
