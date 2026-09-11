<?php

namespace App\Services\Directory;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportMetadata
{
    public function make(Request $request, array $facility, array $filters, array $labels, string $entity, bool $detail, string $definition): array
    {
        $issued = now($facility['timezone']);
        $number = DB::transaction(function () use ($facility, $issued, $entity) {
            $key = ['sequence_key' => $entity.'_report', 'scope_key' => 'facility:'.$facility['id'], 'period_key' => $issued->format('Y')];
            DB::table('number_sequences')->insertOrIgnore($key + ['current_value' => 0, 'created_at' => now()]);
            $sequence = DB::table('number_sequences')->where($key)->lockForUpdate()->first();
            DB::table('number_sequences')->where('id', $sequence->id)->update(['current_value' => $sequence->current_value + 1, 'updated_at' => now()]);

            return ($entity === 'clinic' ? 'CL' : 'DR').'-'.$facility['id'].'-'.$issued->format('Y').'-'.str_pad((string) ($sequence->current_value + 1), 6, '0', STR_PAD_LEFT);
        }, 3);
        $parts = [];
        if (isset($filters['search'])) {
            $parts[] = 'البحث: '.$filters['search'];
        }
        $parts[] = 'الحالة: '.match ($filters['status'] ?? '') {
            'active' => 'فعال', 'inactive' => 'غير فعال', 'archived' => 'مؤرشف', default => 'الفعال والمعطل'
        };
        if (! empty($filters['specialty_id'])) {
            $parts[] = 'التخصص: '.(DB::table('specialties')->where('id', $filters['specialty_id'])->value('name_ar') ?? 'غير متاح');
        }
        if (! empty($filters['clinic_id'])) {
            $parts[] = 'العيادة: '.(DB::table('clinics')->where('facility_id', $facility['id'])->where('id', $filters['clinic_id'])->value('name_ar') ?? 'غير متاحة');
        }
        if (! empty($filters['doctor_id'])) {
            $parts[] = 'الطبيب: '.(DB::table('staff as s')->join('staff_types as st', 'st.id', '=', 's.staff_type_id')->whereIn('st.code', config('clinics.doctor_staff_types'))->where('s.id', $filters['doctor_id'])->value('s.full_name') ?? 'غير متاح');
        }
        $parts[] = 'الترتيب: '.($labels[$filters['sort'] ?? 'code'] ?? 'الحالة').' '.(($filters['direction'] ?? 'asc') === 'desc' ? 'تنازليًا' : 'تصاعديًا');

        return ['title' => $entity === 'clinic' ? ($detail ? 'تفاصيل عيادة' : 'قائمة العيادات') : ($detail ? 'تفاصيل طبيب' : 'قائمة الأطباء'),
            'number' => $number, 'issued_at' => $issued->format('Y-m-d H:i:s'), 'timezone' => $facility['timezone'],
            'issuer' => $request->user()->name, 'facility' => $facility['name_ar'], 'filters' => $detail ? 'تقرير تفاصيل مستقل ضمن المنشأة المحددة' : implode(' | ', $parts), 'definition' => $definition];
    }
}
