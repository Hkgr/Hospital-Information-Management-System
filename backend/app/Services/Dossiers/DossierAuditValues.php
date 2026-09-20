<?php

namespace App\Services\Dossiers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Public audit values are an allowlisted projection, never a serialized row/request. */
class DossierAuditValues
{
    private const FIELDS = [
        'supporting_attachment_id' => 'المرفق الداعم',
        'source' => 'مصدر التقرير', 'report_number' => 'رقم التقرير', 'external_organization' => 'الجهة الخارجية', 'specimen_type' => 'نوع العينة', 'anatomical_site' => 'الموقع التشريحي', 'requested_on' => 'تاريخ الطلب', 'collected_on' => 'تاريخ جمع العينة', 'result_on' => 'تاريخ النتيجة', 'conclusion' => 'الخلاصة النهائية', 'unavailable_reason' => 'سبب عدم الإتاحة أو الإلغاء', 'procedure_event_id' => 'واقعة الإجراء', 'doctor_id' => 'الطبيب المسؤول', 'disposition' => 'التقييم التشخيصي', 'assessed_on' => 'تاريخ التقييم', 'required_reason' => 'سبب طلب التشريح', 'not_required_reason' => 'سبب عدم الحاجة للتشريح', 'follow_up' => 'المتابعة', 'evidence_pathology_id' => 'التقرير الداعم',
        'code' => 'معرّف السياق التاريخي (غير الكود الحالي)', 'opening_date' => 'بداية الملف الطبي في المشفى', 'status' => 'الحالة',
        'patient_code' => 'كود المريض', 'first_name' => 'الاسم الأول', 'family_name' => 'العائلة', 'father_name' => 'اسم الأب', 'mother_name' => 'اسم الأم',
        'birth_date' => 'الميلاد', 'birth_date_accuracy' => 'دقة الميلاد', 'gender' => 'الجنس', 'phone' => 'الهاتف', 'alt_phone' => 'هاتف بديل',
        'governorate_id' => 'المحافظة', 'city_id' => 'المدينة', 'address_line' => 'عنوان السكن', 'displacement_status' => 'حالة النزوح',
        'is_oncology' => 'ملف ورمي', 'disability_text' => 'معلومات الإعاقة', 'clinical_history' => 'القصة المرضية',
        'previous_examinations' => 'الفحوص السابقة', 'medication_source' => 'مصدر الدواء', 'other_organization' => 'الجهة الأخرى', 'selections' => 'السوابق والعلاجات الورمية',
        'visit_no' => 'كود الزيارة', 'visit_date' => 'تاريخ الزيارة', 'visit_type_id' => 'نوع الزيارة', 'dossier_visit_kind' => 'نوع زيارة بطاقة المريض',
        'is_referred' => 'إحالة واردة', 'referring_hospital' => 'المشفى المحيل', 'referral_date' => 'تاريخ الإحالة الواردة', 'referral_reason' => 'سبب الإحالة الواردة',
        'diagnosis_id' => 'التشخيص', 'diagnosed_on' => 'تاريخ التشخيص', 'clinic_id' => 'العيادة', 'attending_staff_id' => 'الطبيب المسؤول', 'diagnosing_staff_id' => 'الطبيب المشخّص',
        'service_id' => 'الخدمة', 'procedure_id' => 'الإجراء', 'performed_by' => 'الطبيب المسؤول', 'specialist_id' => 'الطبيب المختص', 'performed_on' => 'تاريخ التقديم',
        'prescribing_clinic_id' => 'عيادة الوصفة', 'prescribing_staff_id' => 'طبيب الوصفة', 'prescribed_on' => 'تاريخ الوصفة',
        'medication_code_snapshot' => 'كود الدواء المحفوظ', 'medication_name_snapshot' => 'اسم الدواء المحفوظ', 'display_order' => 'ترتيب البند',
        'result_id' => 'نتيجة الزيارة', 'decided_by' => 'طبيب النتيجة', 'outcome_on' => 'تاريخ النتيجة', 'referral_target' => 'جهة الإحالة الصادرة',
        'outgoing_referral_date' => 'تاريخ الإحالة الصادرة', 'outgoing_referral_reason' => 'سبب الإحالة الصادرة',
        'note' => 'الملاحظة', 'quantity' => 'الكمية المسجلة', 'quantity_unit' => 'وحدة الكمية', 'title' => 'عنوان المرفق',
        'original_filename' => 'اسم الملف الأصلي', 'size' => 'حجم الملف بالبايت', 'state' => 'حالة الرفع', 'voided_at' => 'وقت الإلغاء', 'void_reason' => 'سبب الإلغاء',
    ];

    private const GROUPS = [
        'visit_pathologies' => ['source', 'status', 'report_number', 'external_organization', 'specimen_type', 'anatomical_site', 'requested_on', 'collected_on', 'result_on', 'conclusion', 'unavailable_reason', 'procedure_event_id', 'clinic_id', 'doctor_id', 'supporting_attachment_id'],
        'visit_diagnostic_assessments' => ['disposition', 'assessed_on', 'required_reason', 'not_required_reason', 'follow_up', 'evidence_pathology_id', 'clinic_id', 'doctor_id'],
        'patient_dossier' => ['code', 'opening_date', 'status'],
        'patient' => ['patient_code', 'first_name', 'family_name', 'father_name', 'mother_name', 'birth_date', 'birth_date_accuracy', 'gender', 'phone', 'alt_phone', 'governorate_id', 'city_id', 'address_line', 'displacement_status'],
        'dossier_medical' => ['is_oncology', 'disability_text', 'clinical_history', 'previous_examinations', 'medication_source', 'other_organization', 'selections'],
        'dossier_visit' => ['visit_no', 'visit_date', 'visit_type_id', 'dossier_visit_kind', 'status', 'clinic_id', 'attending_staff_id', 'is_referred', 'referring_hospital', 'referral_date', 'referral_reason'],
        'visit_diagnosis' => ['diagnosis_id', 'diagnosed_on', 'clinic_id', 'diagnosing_staff_id'],
        'visit_services' => ['service_id', 'performed_on', 'performed_by', 'clinic_id', 'quantity', 'quantity_unit'],
        'visit_procedures' => ['procedure_id', 'performed_on', 'specialist_id', 'clinic_id', 'quantity', 'quantity_unit'],
        'visit_prescriptions' => ['prescribing_clinic_id', 'prescribing_staff_id', 'prescribed_on'],
        'visit_prescription_items' => ['medication_code_snapshot', 'medication_name_snapshot', 'display_order'],
        'visit_outcomes' => ['result_id', 'decided_by', 'clinic_id', 'outcome_on', 'referral_target', 'outgoing_referral_date', 'outgoing_referral_reason'],
        'dossier_upload' => ['state', 'title', 'original_filename'],
        'visit_attachment' => ['title', 'original_filename', 'size'],
    ];

    private const REFERENCES = ['governorate_id' => ['governorates', 'name_ar'], 'city_id' => ['cities', 'name_ar'], 'visit_type_id' => ['visit_types', 'name_ar'],
        'diagnosis_id' => ['diagnoses', 'name_ar'], 'service_id' => ['services', 'name_ar'], 'procedure_id' => ['procedures', 'name_ar'], 'result_id' => ['visit_results', 'name_ar'],
        'clinic_id' => ['clinics', 'name_ar'], 'prescribing_clinic_id' => ['clinics', 'name_ar'],
        'attending_staff_id' => ['staff', 'full_name'], 'diagnosing_staff_id' => ['staff', 'full_name'], 'performed_by' => ['staff', 'full_name'], 'specialist_id' => ['staff', 'full_name'], 'prescribing_staff_id' => ['staff', 'full_name'], 'decided_by' => ['staff', 'full_name']];

    private array $names = [];

    public function __construct(array $f, Collection $rows)
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ([$row->old_values, $row->new_values] as $json) {
                $values = json_decode($json ?? '{}', true) ?: [];
                foreach (self::REFERENCES as $field => [$table]) {
                    if (isset($values[$field]) && is_numeric($values[$field])) {
                        $ids[$table][] = (int) $values[$field];
                    }
                }
            }
        }
        foreach (self::REFERENCES as [$table, $name]) {
            if (isset($this->names[$table]) || empty($ids[$table])) {
                continue;
            }
            $q = DB::table($table)->whereIn('id', array_unique($ids[$table]));
            if ($table === 'clinics') {
                $q->where('facility_id', $f['id']);
            } elseif ($table === 'staff') {
                $q->whereIn('id', DB::table('clinic_staff as cs')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')->where('c.facility_id', $f['id'])->select('cs.staff_id'));
            }
            $this->names[$table] = $q->pluck($name, 'id')->all();
        }
    }

    public function changes(string $entity, array $old, array $new): array
    {
        $changes = [];
        foreach ([...self::GROUPS[$entity], 'note', 'voided_at', 'void_reason'] as $field) {
            // New audit snapshots can be partial. Omitted fields are not removals.
            if (! array_key_exists($field, $new)) {
                continue;
            }
            $before = array_key_exists($field, $old) ? $this->value($field, $old[$field]) : null;
            $after = $this->value($field, $new[$field]);
            if ($before === $after) {
                continue;
            }
            $changes[] = ['field' => $field, 'label' => self::FIELDS[$field], 'before' => $before, 'after' => $after, 'before_recorded' => array_key_exists($field, $old)];
        }

        return $changes;
    }

    private function value(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'غير مسجل';
        }
        if (in_array($field, ['is_oncology', 'is_referred'], true)) {
            return $value ? 'نعم' : 'لا';
        }
        if (isset(self::REFERENCES[$field])) {
            $id = is_numeric($value) ? (int) $value : 0;

            return ($this->names[self::REFERENCES[$field][0]][$id] ?? 'اسم غير متاح').' (#'.$id.'؛ اسم الدليل الحالي)';
        }
        if ($field === 'selections') {
            $groups = ['history' => 'سوابق', 'treatment' => 'علاج'];
            $codes = ['medical' => 'مرضية', 'surgical' => 'جراحية', 'medication' => 'دوائية', 'family' => 'عائلية', 'chemotherapy' => 'كيميائي', 'radiotherapy' => 'شعاعي', 'other' => 'آخر'];
            $selected = [];
            foreach (is_array($value) ? $value : [] as $row) {
                if (is_array($row) && isset($groups[$row['selection_group'] ?? ''], $codes[$row['code'] ?? ''])) {
                    $selected[] = $groups[$row['selection_group']].': '.$codes[$row['code']].(! empty($row['is_active']) ? ' (فعال)' : ' (غير فعال)');
                }
            }
            sort($selected);

            return implode('، ', array_unique($selected)) ?: 'لا توجد اختيارات مسجلة';
        }
        if (! is_scalar($value)) {
            return 'قيمة غير قابلة للعرض';
        }
        if ($field === 'disposition') {
            return DossierPathology::DISPOSITIONS[$value] ?? (string) $value;
        }
        if ($field === 'source') {
            return ['internal' => 'ضمن المشفى', 'external' => 'من جهة خارجية'][$value] ?? (string) $value;
        }
        if ($field === 'status' && isset(DossierPathology::STATUSES[$value])) {
            return DossierPathology::STATUSES[$value];
        }
        $enums = ['status' => ['draft' => 'مسودة', 'active' => 'فعالة', 'complete' => 'مكتملة', 'void' => 'ملغاة', 'voided' => 'ملغاة'],
            'gender' => ['male' => 'ذكر', 'female' => 'أنثى', 'unknown' => 'غير معروف'], 'birth_date_accuracy' => ['exact' => 'دقيق', 'year_only' => 'السنة فقط', 'estimated' => 'تقديري', 'unknown' => 'غير معروف'],
            'displacement_status' => ['resident' => 'مقيم', 'idp' => 'نازح', 'returnee' => 'عائد', 'unknown' => 'غير معروف'],
            'dossier_visit_kind' => ['initial' => 'أول زيارة مسجلة ضمن البطاقة', 'subsequent' => 'لاحقة'], 'state' => ['pending' => 'قيد الرفع', 'complete' => 'مكتمل', 'cancelled' => 'ملغى'],
            'medication_source' => ['ministry_of_health' => 'وزارة الصحة', 'al_rowad' => 'مؤسسة الرواد', 'other_organization' => 'جهة أخرى', 'personal_expense' => 'نفقة شخصية', 'none' => 'لا يوجد']];

        return $enums[$field][(string) $value] ?? (string) $value;
    }
}
