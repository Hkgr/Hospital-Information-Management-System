<?php

namespace App\Services\Dossiers;

use App\Services\BloodBank\BloodBankReports;
use App\Services\Directory\DirectoryReport;
use App\Services\Directory\ReportMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DossierReports
{
    public const COLUMNS = ['sequence' => 'م', 'code' => 'كود الإضبارة', 'patient_code' => 'كود المريض', 'name' => 'اسم المريض', 'diagnoses' => 'تشخيصات آخر زيارة', 'clinics' => 'العيادات', 'doctors' => 'الأطباء المسؤولون', 'visit_count' => 'عدد الزيارات', 'procedure_count' => 'عدد الإجراءات', 'status' => 'حالة الإضبارة', 'latest_visit_date' => 'تاريخ آخر زيارة'];

    private function section(string $title, array $labels, array $rows, string $note = '', array $types = []): array
    {
        return compact('title', 'labels', 'rows', 'note', 'types') + ['empty' => 'لا توجد بيانات محفوظة لهذا القسم. في المسودة يمكن استكماله لاحقًا.'];
    }

    private function limit(int $count, int $limit): void
    {
        if ($count > $limit) {
            throw ValidationException::withMessages(['export' => "التقرير يتجاوز الحد الآمن ($limit سجل). ضيّق الفلاتر؛ لم يُنشأ تقرير جزئي."]);
        }
    }

    private function facts(string $title, array $facts, array $identity, array $types = []): array
    {
        $rows = [];
        foreach ($facts as $field => $value) {
            $rows[] = ['field' => $field, 'value' => $value === null || $value === '' ? 'غير مسجل' : $value, '_types' => $value !== null && $value !== '' && isset($types[$field]) ? ['value' => $types[$field]] : []] + $identity;
        }

        return $this->section($title, ['field' => 'البيان', 'value' => 'القيمة'], $rows);
    }

    private function historicalState(object $row, object $visit): string
    {
        $void = $row->voided_at ?? $row->parent_voided_at ?? null;
        $reason = $row->void_reason ?? $row->parent_void_reason ?? null;
        $state = $void ? 'ملغاة' : 'محفوظة';
        $text = 'حالة الواقعة: '.$state.($reason ? '؛ السبب: '.$reason : '');
        if (! empty($row->voided_at) && ! empty($row->parent_voided_at)) {
            $text .= '؛ إلغاء السجل الحاوي: '.$row->parent_void_reason;
        }
        if ($visit->voided_at) {
            $text .= '؛ الزيارة ملغاة: '.$visit->void_reason;
        } else {
            $text .= '؛ الزيارة '.($visit->status === 'draft' ? 'مسودة' : 'مكتملة');
        }
        if (isset($row->lock_version)) {
            $text .= '؛ النسخة المحفوظة: '.$row->lock_version;
        }

        return $text;
    }

    public function document(Request $r, array $f, array $filters, ?int $dossier = null, ?int $visit = null): array
    {
        $queries = app(DossierQueries::class);
        [$sections,$identity,$draft] = DB::transaction(function () use ($queries, $f, $filters, $dossier, $visit) {
            if (! $dossier) {
                $data = $queries->listing($f, array_replace($filters, ['page' => 1, 'per_page' => config('dossiers.export_limit') + 1]));
                $this->limit($data['totals']['dossiers'], config('dossiers.export_limit'));
                $rows = [];
                foreach ($data['data'] as $i => $d) {
                    $rows[] = ['id' => $d['id'], 'sequence' => $i + 1, 'code' => $d['code'], 'patient_code' => $d['patient_code'], 'name' => $d['patient_name'], 'diagnoses' => implode('، ', array_column($d['diagnoses'], 'name')), 'clinics' => implode('، ', array_unique(array_filter(array_column($d['diagnoses'], 'clinic')))), 'doctors' => implode('، ', array_unique(array_filter(array_column($d['diagnoses'], 'doctor')))), 'visit_count' => $d['visit_count'], 'procedure_count' => $d['procedure_count'], 'status' => $d['status'] === 'draft' ? 'مسودة — غير مكتملة' : 'فعالة', 'latest_visit_date' => $d['latest_visit_date']];
                }
                $labels = array_intersect_key(self::COLUMNS, array_flip($filters['columns'] ?? array_keys(self::COLUMNS)));

                return [[$this->section('قائمة الإضبارات', $labels, $rows, 'جميع النتائج المطابقة للفلاتر؛ لا تقتصر على الصفحة المعروضة.', ['sequence' => 'integer', 'visit_count' => 'integer', 'procedure_count' => 'integer', 'latest_visit_date' => 'date'])], null, false];
            }
            $d = $queries->detail($f, $dossier);
            $identity = ['id' => $dossier, 'code' => $d['code'], 'name' => trim($d['patient']['first_name'].' '.$d['patient']['family_name'])];
            $historical = ! $visit;
            $q = $historical
                ? DB::table('visits as v')->join('patient_dossiers as scope', fn ($j) => $j->on('scope.id', '=', 'v.dossier_id')->on('scope.patient_id', '=', 'v.patient_id')->on('scope.facility_id', '=', 'v.facility_id'))->where('v.facility_id', $f['id'])->whereIn('v.status', ['draft', 'complete', 'void'])->where('v.visit_date', '<=', $f['today'])->select('v.*')
                : $queries->actualVisits($f);
            $q->where('v.dossier_id', $dossier);
            if ($historical) {
                $q->when(! empty($filters['from']), fn ($q) => $q->where('v.visit_date', '>=', $filters['from']))
                    ->when(! empty($filters['to']), fn ($q) => $q->where('v.visit_date', '<=', $filters['to']));
            }
            if ($visit) {
                $q->where('v.id', $visit);
            }
            $visits = $q->orderByDesc('v.visit_date')->orderByDesc('v.id')->limit(config('dossiers.report_detail_limit') + 1)->get();
            if ($visit) {
                abort_unless($visits->count(), 404);
            }
            $this->limit($visits->count(), config('dossiers.report_detail_limit'));
            $draft = $visit ? $visits->first()->status === 'draft' : $d['status'] === 'draft';
            $sections = [$this->facts('هوية الإضبارة', ['كود الإضبارة' => $d['code'], 'تاريخ فتح الإضبارة' => $d['opening_date'], 'المريض' => $identity['name'], 'كود المريض' => $d['patient']['patient_code'], 'حالة الإضبارة' => $d['status'] === 'draft' ? 'مسودة — غير مكتملة' : 'فعالة', 'آخر زيارة فعلية' => $d['latest_visit'] ? ($d['latest_visit']['visit_no'].' · '.$d['latest_visit']['visit_date'].' · '.($d['latest_visit']['status'] === 'draft' ? 'مسودة — غير مكتملة' : 'مكتملة')) : 'لا توجد زيارة مسجلة'], $identity, ['تاريخ فتح الإضبارة' => 'date'])];
            $labels = ['first_name' => 'الاسم الأول', 'family_name' => 'العائلة', 'father_name' => 'اسم الأب', 'mother_name' => 'اسم الأم', 'birth_date' => 'الميلاد', 'birth_date_accuracy' => 'دقة الميلاد', 'gender' => 'الجنس', 'phone' => 'الهاتف', 'alt_phone' => 'هاتف بديل', 'governorate' => 'المحافظة', 'city' => 'المدينة', 'address_line' => 'العنوان', 'displacement_status' => 'حالة النزوح'];
            $values = ['unknown' => 'غير معروف', 'male' => 'ذكر', 'female' => 'أنثى', 'exact' => 'دقيق', 'year_only' => 'السنة فقط', 'estimated' => 'تقديري', 'resident' => 'مقيم', 'idp' => 'نازح', 'returnee' => 'عائد'];
            $personal = [];
            foreach ($labels as $key => $label) {
                $personal[$label] = $values[$d['patient'][$key] ?? ''] ?? $d['patient'][$key];
            }
            $sections[] = $this->facts('بيانات المريض الحالية', $personal, $identity, ['الميلاد' => 'date']);
            $medical = ['معلومات الإعاقة' => $d['disability_text'], 'القصة المرضية' => $d['clinical_history'], 'مريض ورمي' => $d['is_oncology'] ? 'نعم' : 'لا'];
            if ($d['oncology']) {
                $codes = ['medical' => 'مرضية', 'surgical' => 'جراحية', 'medication' => 'دوائية', 'family' => 'عائلية', 'chemotherapy' => 'كيميائي', 'radiotherapy' => 'شعاعي', 'other' => 'أخرى'];
                $medical += ['السوابق والعلاجات' => implode('، ', array_map(fn ($s) => $codes[$s->code] ?? $s->code, $d['oncology']['selections'])), 'الفحوص السابقة' => $d['oncology']['previous_examinations'], 'مصدر الدواء' => ['ministry_of_health' => 'وزارة الصحة', 'al_rowad' => 'مؤسسة الرواد', 'other_organization' => 'جهة أخرى', 'personal_expense' => 'نفقة شخصية', 'none' => 'لا يوجد'][$d['oncology']['medication_source'] ?? ''] ?? 'غير مسجل', 'الجهة الأخرى' => $d['oncology']['other_organization']];
            }
            $sections[] = $this->facts('المعلومات الطبية الحالية', $medical, $identity);
            $sections[] = $this->section('التسلسل الزمني للزيارات', ['code' => 'كود الزيارة', 'date' => 'التاريخ الفعلي', 'status' => 'حالة الزيارة', 'referral' => 'الإحالة الواردة'], $visits->map(fn ($v) => ['id' => $v->id, 'code' => $v->visit_no, 'name' => $identity['name'], 'date' => $v->visit_date, 'status' => $v->voided_at ? 'ملغاة؛ السبب: '.$v->void_reason : ($v->status === 'draft' ? 'مسودة — غير مكتملة' : 'مكتملة'), 'referral' => $v->is_referred ? implode(' · ', [$v->referring_hospital, $v->referral_date, $v->referral_reason]) : 'غير محال'])->all(), 'الأحدث أولًا حسب التاريخ الفعلي ثم معرّف الزيارة. الإحالة الواردة مستقلة عن النتيجة والإحالة الصادرة.', ['date' => 'date']);
            $ids = $visits->pluck('id');
            $byId = $visits->keyBy('id');
            $total = $visits->count();
            $common = ['code' => 'كود الزيارة', 'date' => 'التاريخ', 'name' => 'البيان', 'clinic' => 'العيادة', 'doctor' => 'الطبيب', 'note' => 'التفاصيل'];
            $specs = [
                ['التشخيصات', 'visit_diagnoses', 'diagnoses', 'diagnosis_id', 'diagnosing_staff_id', 'diagnosed_on'],
                ['الخدمات', 'visit_services', 'services', 'service_id', 'performed_by', 'performed_on'],
                ['الإجراءات', 'visit_procedures', 'procedures', 'procedure_id', 'specialist_id', 'performed_on'],
                ['النتائج والإحالات الصادرة', 'visit_outcomes', 'visit_results', 'result_id', 'decided_by', 'outcome_on'],
            ];
            foreach ($specs as [$title,$table,$catalog,$fk,$doctor,$date]) {
                $rows = DB::table($table.' as e')->join($catalog.' as n', 'n.id', '=', 'e.'.$fk)->leftJoin('clinics as c', 'c.id', '=', 'e.clinic_id')->leftJoin('staff as s', 's.id', '=', 'e.'.$doctor)->where('e.facility_id', $f['id'])->whereIn('e.visit_id', $ids)->when(! $historical, fn ($q) => $q->whereNull('e.voided_at'))->orderBy('e.visit_id')->orderBy('e.id')->limit(config('dossiers.report_detail_limit') + 1)->get(['e.*', 'n.name_ar as label', 'c.name_ar as clinic', 's.full_name as doctor']);
                $total += $rows->count();
                $this->limit($total, config('dossiers.report_detail_limit'));
                $data = $rows->map(function ($e) use ($byId, $date, $table, $historical) {
                    $note = $e->note ?? '';
                    if ($table === 'visit_outcomes' && $e->referral_target) {
                        $note .= "\nالإحالة الصادرة: ".$e->referral_target.' · '.$e->outgoing_referral_date.' · '.$e->outgoing_referral_reason;
                    }
                    if (isset($e->quantity)) {
                        $note .= "\nالكمية المسجلة: ".$e->quantity;
                    }
                    if ($historical) {
                        $note .= "\n".$this->historicalState($e, $byId[$e->visit_id]);
                    }

                    return ['id' => $e->id, 'code' => $byId[$e->visit_id]->visit_no, 'date' => $e->$date, 'name' => $e->label, 'clinic' => $e->clinic, 'doctor' => $e->doctor, 'note' => $note];
                })->all();
                $sections[] = $this->section($title, $common, $data, 'وقائع الزيارة المحفوظة؛ لا يُستنتج الطبيب من بيانات دخول المستخدم.', ['date' => 'date']);
            }
            $rx = DB::table('visit_prescriptions as p')->join('visit_prescription_items as i', 'i.prescription_id', '=', 'p.id')->join('clinics as c', 'c.id', '=', 'p.prescribing_clinic_id')->join('staff as s', 's.id', '=', 'p.prescribing_staff_id')->where('p.facility_id', $f['id'])->where('i.facility_id', $f['id'])->whereIn('p.visit_id', $ids)->when(! $historical, fn ($q) => $q->whereNull('p.voided_at')->whereNull('i.voided_at'))->orderBy('p.visit_id')->orderBy('i.display_order')->orderBy('i.id')->limit(config('dossiers.report_detail_limit') + 1)->get(['i.id', 'p.visit_id', 'p.prescribed_on', 'p.note as general_note', 'i.note', 'i.medication_code_snapshot', 'i.medication_name_snapshot', 'c.name_ar as clinic', 's.full_name as doctor', 'i.voided_at', 'i.void_reason', 'i.lock_version', 'p.voided_at as parent_voided_at', 'p.void_reason as parent_void_reason']);
            $total += $rx->count();
            $this->limit($total, config('dossiers.report_detail_limit'));
            $sections[] = $this->section('الأدوية الموصوفة', $common, $rx->map(fn ($e) => ['id' => $e->id, 'code' => $byId[$e->visit_id]->visit_no, 'date' => $e->prescribed_on, 'name' => $e->medication_code_snapshot.' · '.$e->medication_name_snapshot, 'clinic' => $e->clinic, 'doctor' => $e->doctor, 'note' => implode("\n", array_filter([$e->general_note, $e->note, $historical ? $this->historicalState($e, $byId[$e->visit_id]) : null]))])->all(), 'وصفة فقط؛ لا تمثل صرفًا أو إعطاءً. تعريف الدواء محفوظ وقت إدراجه.', ['date' => 'date']);
            foreach (['dispensed' => 'الأدوية المصروفة', 'administered' => 'الأدوية المعطاة'] as $kind => $title) {
                $q = $kind === 'dispensed' ? DB::table('visit_medications as e')->select('e.*', 'e.dispensed_on as date') : DB::table('dose_session_items as e')->join('dose_sessions as s', 's.id', '=', 'e.dose_session_id')->select('e.*', 's.visit_id', 's.administered_on as date', 's.voided_at as parent_voided_at', 's.void_reason as parent_void_reason')->when(! $historical, fn ($q) => $q->whereNull('s.voided_at'));
                $alias = $kind === 'dispensed' ? 'e' : 's';
                $rows = $q->where($alias.'.facility_id', $f['id'])->whereIn($alias.'.visit_id', $ids)->when($kind === 'dispensed' && ! $historical, fn ($q) => $q->whereNull('e.voided_at'))->orderBy('e.id')->limit(config('dossiers.report_detail_limit') + 1)->get();
                $total += $rows->count();
                $this->limit($total, config('dossiers.report_detail_limit'));
                $sections[] = $this->section($title, ['code' => 'كود الزيارة', 'date' => 'التاريخ', 'name' => 'اسم الدواء المحفوظ', 'note' => 'الجرعة والكمية'], $rows->map(fn ($e) => ['id' => $e->id, 'code' => $byId[$e->visit_id]->visit_no, 'date' => $e->date, 'name' => $e->medication_name_snapshot, 'note' => trim(($e->dose_text ?? '').' · '.$e->quantity.' '.$e->quantity_unit).($historical ? "\n".$this->historicalState($e, $byId[$e->visit_id]) : '')])->all(), 'سجل تاريخي مستقل عن الوصفة؛ لا ينشئ المعالج صرفًا أو إعطاءً.', ['date' => 'date']);
            }
            if ($f['capabilities']['attachments_view']) {
                $rows = DB::table('visit_attachments')->where('facility_id', $f['id'])->where('dossier_id', $dossier)->whereIn('visit_id', $ids)->when(! $historical, fn ($q) => $q->whereNull('voided_at'))->orderBy('id')->limit(config('dossiers.report_detail_limit') + 1)->get();
                $total += $rows->count();
                $this->limit($total, config('dossiers.report_detail_limit'));
                $sections[] = $this->section('بيانات المرفقات', ['code' => 'كود الزيارة', 'name' => 'عنوان الملف', 'filename' => 'اسم الملف الأصلي', 'size' => 'الحجم بالبايت', 'uploaded_at' => 'تاريخ الرفع'], $rows->map(fn ($a) => ['id' => $a->id, 'code' => $byId[$a->visit_id]->visit_no, 'name' => $a->title.($historical ? "\n".$this->historicalState($a, $byId[$a->visit_id]) : ''), 'filename' => $a->original_filename, 'size' => $a->size, 'uploaded_at' => $a->created_at])->all(), 'بيانات وصفية فقط؛ الملفات الخاصة ليست مضمنة في التقرير.', ['size' => 'integer', 'uploaded_at' => 'datetime']);
            }

            return [$sections, $identity, $draft];
        });
        $meta = app(ReportMetadata::class)->make($r, $f, $filters, self::COLUMNS, 'dossier', $dossier !== null, 'بيانات حالية للإضبارة ووقائع تاريخية لكل زيارة؛ الوصفة منفصلة عن الصرف.');
        $meta['title'] = ($visit ? 'تقرير زيارة' : ($dossier ? 'تاريخ الإضبارة الكامل' : 'قائمة الإضبارات')).($draft ? ' — مسودة — غير مكتملة' : '');
        $meta['definition_label'] = 'نطاق التقرير';
        $meta['filters'] = $dossier ? 'تقرير فردي ضمن المنشأة المصرح بها' : implode(' | ', array_map(fn ($key) => ['search' => 'البحث', 'status' => 'الحالة', 'oncology' => 'ورمي', 'visits' => 'الزيارات', 'from' => 'من', 'to' => 'إلى', 'sort' => 'الترتيب', 'direction' => 'الاتجاه'][$key].': '.($filters[$key] ?? 'الكل'), ['search', 'status', 'oncology', 'visits', 'from', 'to', 'sort', 'direction']));
        if ($dossier && ! $visit) {
            $meta['filters'] .= '؛ تاريخ الزيارة الفعلي: '.($filters['from'] ?? 'البداية').' — '.($filters['to'] ?? 'اليوم').'؛ يشمل الوقائع المبطلة وأسبابها؛ كل واقعة تحتفظ بتاريخها الخاص. القيم السابقة للتصحيحات متاحة في سجل التغييرات بصلاحيته المستقلة.';
        }

        return ['metadata' => $meta, 'detail' => $dossier !== null, 'identity' => $identity, 'columns' => array_keys($sections[0]['labels']), 'sections' => $sections, 'moduleLabel' => 'إضبارات المرضى', 'printWidth' => $dossier ? 186 : 273, 'reportNote' => $draft ? 'مسودة — غير مكتملة؛ لا تمثل سجلًا طبيًا مكتملًا.' : 'معلومات الإضبارة الحالية مميزة عن وقائع زياراتها.'];
    }

    public function export(Request $r, array $f, array $filters, string $format, ?int $dossier = null, ?int $visit = null)
    {
        $doc = $this->document($r, $f, $filters, $dossier, $visit);
        $bytes = $format === 'pdf' ? app(DirectoryReport::class)->pdf($doc, 'reports.blood-bank') : app(BloodBankReports::class)->xlsx($doc);
        app(DossierWrites::class)->audit($r, $f, 'dossier_report', $dossier ?? 0, null, ['number' => $doc['metadata']['number'], 'format' => $format, 'visit_id' => $visit], 'exported');

        return response($bytes, 200, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$doc['metadata']['number'].'.'.$format.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
