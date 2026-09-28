<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
body { font-family: cairo; color:#233f3c; font-size:10pt; } h1 {font-size:17pt} h2 {font-size:14pt} h3 {font-size:12pt} table {width:100%; border-collapse:collapse; margin-bottom:12px} th {background:#155c56;color:white} th,td {padding:6px;border:1px solid #d5dfdc;text-align:right} p {line-height:1.7} thead {display:table-header-group}
</style></head><body>
<h1>{{ $data['title'] }}</h1><p>{{ $data['facility']['name_ar'] }} — {{ $data['filters']['from_month'] }} / {{ $data['filters']['to_month'] }}<br>وقت الإصدار: {{ $metadata['issued_at'] }} {{ $data['facility']['timezone'] }}</p>
<p>{{ $data['privacy']['policy'] }}</p><p>{{ $data['occupancy']['reason'] }}</p>
@foreach($data['months'] as $month)
<h2>{{ $month['month'] }}</h2>
@foreach($month['sections'] as $section)
<h3>{{ $section['title'] }}</h3><p>{{ $section['definition'] }}</p>
@if($section['suppressed'])<p>محجوب لحماية الخصوصية</p>
@else
<table><thead><tr><th>الفئة</th><th>مرضى فريدون</th><th>الوقائع</th></tr></thead><tbody>
<tr><td>إجمالي المؤشر (لا تجمع المرضى بين الفئات)</td><td>{{ $section['patients'] }}</td><td>{{ $section['events'] }}</td></tr>
@foreach($section['rows'] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['patients'] }}</td><td>{{ $row['events'] }}</td></tr>@endforeach
</tbody></table>
@endif
@endforeach
@endforeach
</body></html>
