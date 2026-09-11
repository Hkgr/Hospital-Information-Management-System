<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="UTF-8"><style>
body { font-family: dejavusans; color: #183d3b; line-height: 1.6; }
h1 { font-size: 19pt; color: #176b68; } h2 { font-size: 14pt; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; }
th { background: #176b68; color: white; } th, td { padding: 7px; border: 1px solid #d7e3e1; vertical-align: top; overflow-wrap: break-word; }
.meta { font-size: 9pt; color: #475e5d; } .description { white-space: pre-wrap; overflow-wrap: break-word; }
</style></head><body>
<img src="var:hospitalLogo" width="155" alt="هوية المشفى">
<h1>{{ $metadata['title'] }}</h1>
<div class="meta">رقم التقرير: {{ $metadata['number'] }}<br>المنشأة: {{ $metadata['facility'] }}<br>أصدره: {{ $metadata['issuer'] }}<br>وقت الإصدار: {{ $metadata['issued_at'] }}<br>الفلاتر: {{ $metadata['filters'] }}</div>
<p class="meta">{{ $metadata['definition'] }}</p>
@if ($detail)
    @foreach ($rows as $row)
        <h2>{{ $row['name_ar'] }} — {{ $row['code'] }}</h2>
        <p>الحالة: {{ $row['is_active'] ? 'فعالة' : 'غير فعالة' }} | التخصص: {{ $row['specialty_name'] ?? 'غير محدد' }}</p>
        <div class="description">{{ $row['description'] ?? 'لا يوجد توصيف.' }}</div>
        <p>عدد الأطباء: {{ $row['doctor_count'] }} | عدد المرضى: {{ $row['patient_count'] }}</p>
        <h2>الأطباء الحاليون</h2>
        <table><thead><tr><th>كود الطبيب</th><th>الاسم</th><th>بداية الارتباط</th></tr></thead><tbody>
        @forelse ($row['doctors'] as $doctor)<tr><td>{{ $doctor->staff_code }}</td><td>{{ $doctor->full_name }}</td><td>{{ $doctor->starts_on }}</td></tr>
        @empty<tr><td colspan="3">لا يوجد أطباء مرتبطون حاليًا.</td></tr>@endforelse
        </tbody></table>
    @endforeach
@else
    <table><thead><tr>@foreach ($columns as $column)<th>{{ \App\Services\Clinics\ClinicReports::COLUMNS[$column] }}</th>@endforeach</tr></thead><tbody>
    @forelse ($rows as $index => $row)
        <tr>@foreach ($columns as $column)<td>@switch($column)
            @case('number'){{ $index + 1 }}@break
            @case('doctors'){{ implode('، ', array_map(fn ($d) => $d->full_name, $row['doctors'])) }}@break
            @default{{ $row[$column] ?? '—' }}
        @endswitch</td>@endforeach</tr>
    @empty<tr><td colspan="{{ count($columns) }}">لا توجد نتائج مطابقة.</td></tr>@endforelse
    </tbody></table>
@endif
</body></html>
