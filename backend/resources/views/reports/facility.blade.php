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
.facts td { width: 50%; padding: 2mm 3mm; border-bottom: .2mm solid #dce6e3; }
.metric { font-size: 15pt; font-weight: bold; color: #155c56; }
.ltr { direction: ltr; unicode-bidi: embed; text-align: left; }
.center { text-align: center; }
.note { font-size: 9pt; color: #36564e; padding: 2mm 0; }
</style></head><body>
<table class="brand"><tr><td width="76%"><div class="hospital">مشفى محمد بن زايد الإماراتي</div><h1>{{ $metadata['title'] }}</h1><span class="caption">تقرير نشاط المنشأة حسب الفترة الزمنية</span></td><td width="24%" align="left"><img src="var:hospitalLogo" width="132" alt="هوية المشفى"></td></tr></table>
<table class="metadata"><tr><td><span class="label">رقم التقرير</span> &nbsp; <span dir="ltr">{{ $metadata['number'] }}</span></td><td><span class="label">الإصدار</span> &nbsp; <span dir="ltr">{{ $metadata['issued_at'] }}</span> · {{ $metadata['timezone'] }}</td></tr><tr><td><span class="label">المنشأة</span> &nbsp; {{ $metadata['facility'] }}</td><td><span class="label">أصدره</span> &nbsp; {{ $metadata['issuer'] }}</td></tr></table>
<div class="filters"><strong>الفترة:</strong> {{ $metadata['filters'] }}</div>

@if ($report['counters'])
  <h2>المؤشرات</h2>
  <table class="facts">
    @foreach (array_chunk($report['counters'], 2) as $pair)
      <tr>@foreach ($pair as $item)<td><strong class="label">{{ $item['label'] }}</strong><br><span class="metric">{{ $item['value'] }}</span></td>@endforeach @if(count($pair) === 1)<td></td>@endif</tr>
    @endforeach
  </table>
@endif

@if ($report['visit_status'])
  <h2>توزيع الزيارات</h2>
  <table class="data" autosize="1"><thead><tr><th>الحالة</th><th width="30%">العدد</th></tr></thead><tbody>
    @foreach ($report['visit_status'] as $i => $item)<tr class="{{ $i % 2 ? 'stripe' : '' }}"><td>{{ $item['label'] }}</td><td class="center">{{ $item['value'] }}</td></tr>@endforeach
  </tbody></table>
@endif

@if ($report['mix'])
  <h2>مزيج النشاط</h2>
  <table class="data" autosize="1"><thead><tr><th>النوع</th><th width="30%">العدد</th></tr></thead><tbody>
    @foreach ($report['mix'] as $i => $item)<tr class="{{ $i % 2 ? 'stripe' : '' }}"><td>{{ $item['label'] }}</td><td class="center">{{ $item['value'] }}</td></tr>@endforeach
  </tbody></table>
@endif

@if (count($report['series']) > 1)
  <h2>الزيارات حسب اليوم</h2>
  <table class="data" autosize="1"><thead><tr><th>التاريخ</th><th width="30%">العدد</th></tr></thead><tbody>
    @foreach ($report['series'] as $i => $item)<tr class="{{ $i % 2 ? 'stripe' : '' }}"><td class="ltr">{{ $item['label'] }}</td><td class="center">{{ $item['value'] }}</td></tr>@endforeach
  </tbody></table>
@endif

@foreach ([['clinics', 'العيادات الأكثر نشاطًا'], ['doctors', 'الأطباء الأكثر نشاطًا'], ['procedures', 'الإجراءات الأكثر تنفيذًا']] as [$key, $title])
  @if ($report[$key])
    <h2>{{ $title }}</h2>
    <table class="data" autosize="1"><thead><tr><th>الترتيب</th><th>الاسم</th><th width="24%">العدد</th></tr></thead><tbody>
      @foreach ($report[$key] as $i => $item)<tr class="{{ $i % 2 ? 'stripe' : '' }}"><td class="center">{{ $i + 1 }}</td><td>{{ $item['name_ar'] }}</td><td class="center">{{ $item['visit_count'] }}</td></tr>@endforeach
    </tbody></table>
  @endif
@endforeach

@if ($report['patients'])
  <h2>جدول المرضى</h2>
  <table class="data" autosize="1"><thead><tr><th width="22%">كود المريض</th><th width="40%">اسم المريض</th><th width="16%">عدد الزيارات</th><th width="22%">آخر زيارة</th></tr></thead><tbody>
    @foreach ($report['patients'] as $i => $patient)<tr class="{{ $i % 2 ? 'stripe' : '' }}"><td class="ltr">{{ $patient['patient_code'] }}</td><td>{{ $patient['patient_name'] }}</td><td class="center">{{ $patient['visit_count'] }}</td><td class="ltr">{{ $patient['last_visit_on'] }}</td></tr>@endforeach
  </tbody></table>
  <p class="note"><strong>احتساب المرضى:</strong> {{ $report['patients_definition'] }}</p>
@elseif (! $report['counters'] && ! $report['clinics'] && ! $report['doctors'])
  <p class="note">لا توجد مؤشرات متاحة لصلاحياتك في هذه المنشأة خلال الفترة المحددة.</p>
@endif
</body></html>
