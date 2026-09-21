<?php

namespace App\Services\Dossiers;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OncologyReports
{
    public const COLUMNS = ['treatment_count' => 'عدد الخطط', 'active_treatment_count' => 'الخطط الفعالة', 'review_treatment_count' => 'خطط تحتاج مراجعة', 'treatment_modalities' => 'أنماط العلاج الفعال', 'next_dose_on' => 'الجرعة المجدولة القادمة', 'last_dose_on' => 'آخر إعطاء فعلي'];

    public const TYPES = ['treatment_count' => 'integer', 'active_treatment_count' => 'integer', 'review_treatment_count' => 'integer', 'next_dose_on' => 'date', 'last_dose_on' => 'date'];

    private function section(string $title, array $rows): array
    {
        return ['title' => $title, 'labels' => ['code' => 'المرجع', 'field' => 'البيان', 'value' => 'القيمة'], 'rows' => $rows, 'note' => 'الخطة والموعد ليسا إعطاءً أو صرفًا. كل واقعة فعلية مرتبطة بزيارتها ونسختها المحفوظة.', 'types' => [], 'empty' => 'لا توجد وقائع محفوظة في هذا النطاق.'];
    }

    private function facts(string $code, array $fields, array $types = []): array
    {
        $rows = [];
        foreach ($fields as $label => $value) {
            $rows[] = ['id' => $code, 'name' => $code, 'code' => $code, 'field' => $label, 'value' => $value === null || $value === '' ? 'غير مسجل' : $value, '_types' => $value !== null && $value !== '' && isset($types[$label]) ? ['value' => $types[$label]] : []];
        }

        return $rows;
    }

    public function sections(array $f, int $dossier, array $visitIds, bool $individualVisit): array
    {
        if (! ($f['capabilities']['treatment_view'] ?? false)) {
            return [];
        }
        $max = config('dossiers.report_detail_limit');
        $result = [];
        $total = 0;
        $check = function (int $n) use (&$total, $max) {
            $total += $n;
            if ($total > $max) {
                throw ValidationException::withMessages(['export' => 'سجل العلاج يتجاوز الحد الآمن؛ ضيّق نطاق التقرير.']);
            }
        };
        if (! $individualVisit) {
            $plans = app(OncologyQueries::class)->plans($f)->where('p.dossier_id', $dossier)->select('p.*')->selectRaw(OncologyQueries::effectiveSql().' AS effective_status')->limit($max + 1)->get()->keyBy('id');
            $check($plans->count());
            $revisions = DB::table('oncology_plan_revisions as r')->join('clinics as pc', 'pc.id', '=', 'r.protocol_clinic_id')->join('staff as pd', 'pd.id', '=', 'r.protocol_doctor_id')->join('clinics as tc', 'tc.id', '=', 'r.treating_clinic_id')->join('staff as td', 'td.id', '=', 'r.treating_doctor_id')->whereIn('r.plan_id', $plans->keys())->orderBy('r.plan_id')->orderBy('r.revision_number')->limit($max + 1)->get(['r.*', 'pc.name_ar as protocol_clinic', 'pd.full_name as protocol_doctor', 'tc.name_ar as treating_clinic', 'td.full_name as treating_doctor']);
            $check($revisions->count());
            $rows = [];
            foreach ($revisions as $r) {
                $p = $plans[$r->plan_id];
                array_push($rows, ...$this->facts($p->plan_number.' / '.$r->revision_number, ['حالة الخطة الحالية' => OncologyQueries::STATUSES[$p->effective_status], 'النية' => OncologyQueries::INTENTS[$r->intent], 'النمط' => OncologyQueries::MODALITIES[$r->modality], 'البروتوكول' => $r->protocol_text, 'طبيب البروتوكول' => $r->protocol_doctor, 'عيادة البروتوكول' => $r->protocol_clinic, 'الطبيب المعالج' => $r->treating_doctor, 'عيادة الطبيب المعالج' => $r->treating_clinic, 'أساس الاعتماد الحالي' => DossierPathology::DISPOSITIONS[$p->basis_disposition] ?? 'غير معتمد', 'استثناء التشريح' => $p->override_reason, 'سبب آخر إجراء' => $p->status_reason, 'تاريخ المراجعة' => $p->reviewed_at], ['تاريخ المراجعة' => 'datetime']));
            }
            $check(count($rows));
            $result[] = $this->section('الخطط العلاجية ونسخها', $rows);
            $sessions = DB::table('oncology_sessions as s')->leftJoin('oncology_plans as p', 'p.id', '=', 's.plan_id')->leftJoin('oncology_plan_revisions as r', 'r.id', '=', 's.revision_id')->leftJoin('dose_sessions as dose', fn ($j) => $j->on('dose.oncology_session_id', '=', 's.id')->whereNull('dose.voided_at'))->where('s.dossier_id', $dossier)->where('s.facility_id', $f['id'])->orderBy('s.planned_on')->orderBy('s.id')->limit($max + 1)->get(['s.*', 'p.plan_number', 'r.revision_number', 'dose.visit_id', 'dose.administered_on', DB::raw(OncologyQueries::voidedDoseSql())]);
            $check($sessions->count());
            $rows = [];
            foreach ($sessions as $s) {
                array_push($rows, ...$this->facts(($s->plan_id ? $s->plan_number.' / '.$s->session_number : 'موعد مستقل #'.$s->id), ['النسخة' => $s->revision_number, 'موعد مخطط' => $s->planned_on, 'حالة الموعد' => OncologyQueries::SESSION_STATUSES[$s->status], 'سبب الحالة' => $s->reason, 'تاريخ إعطاء فعلي' => $s->administered_on, 'معرّف الزيارة الفعلية' => $s->visit_id, 'سجل الإعطاء المبطل' => $s->has_voided_dose ? 'توجد وقائع إعطاء مبطلة محفوظة تاريخيًا' : null, 'ملاحظة' => $s->note], ['النسخة' => 'integer', 'موعد مخطط' => 'date', 'تاريخ إعطاء فعلي' => 'date']));
            }
            $check(count($rows));
            $result[] = $this->section('سجل الجرعات المجدولة', $rows);
            $history = DB::table('audit_logs as a')->join('oncology_sessions as s', 's.id', '=', 'a.entity_id')->leftJoin('oncology_plans as p', 'p.id', '=', 's.plan_id')->where('a.entity_type', 'oncology_sessions')->where('a.facility_id', $f['id'])->where('s.dossier_id', $dossier)->whereNotNull('a.old_values')->orderBy('a.id')->limit($max + 1)->get(['a.old_values', 'a.new_values', 'a.occurred_at', 'p.plan_number', 's.session_number', 's.id as session_id']);
            $check($history->count());
            $rows = [];
            foreach ($history as $h) {
                $old = json_decode($h->old_values, true);
                $new = json_decode($h->new_values, true);
                array_push($rows, ...$this->facts(($h->plan_number ? $h->plan_number.' / '.$h->session_number : 'موعد مستقل #'.$h->session_id), ['التاريخ السابق' => $old['planned_on'] ?? null, 'التاريخ الجديد' => $new['planned_on'] ?? null, 'الحالة' => OncologyQueries::SESSION_STATUSES[$new['status']] ?? '', 'السبب' => $new['reason'] ?? null, 'وقت التسجيل' => $h->occurred_at], ['التاريخ السابق' => 'date', 'التاريخ الجديد' => 'date', 'وقت التسجيل' => 'datetime']));
            }
            $check(count($rows));
            $result[] = $this->section('تصحيحات المواعيد', $rows);
        }
        $doses = DB::table('dose_sessions as d')->join('visits as v', 'v.id', '=', 'd.visit_id')->leftJoin('oncology_sessions as s', 's.id', '=', 'd.oncology_session_id')->leftJoin('oncology_plans as p', 'p.id', '=', 's.plan_id')->leftJoin('oncology_plan_revisions as revision', 'revision.id', '=', 'd.plan_revision_id')->leftJoin('staff as supervising', 'supervising.id', '=', 'd.supervising_staff_id')->leftJoin('staff as administering', 'administering.id', '=', 'd.administered_by')->where('d.facility_id', $f['id'])->whereIn('d.visit_id', $visitIds)->when($individualVisit, fn ($q) => $q->whereNull('d.voided_at'))->orderBy('d.id')->limit($max + 1)->get(['d.*', 'revision.revision_number', 'v.visit_no', 'p.plan_number', 'supervising.full_name as supervising', 'administering.full_name as administering']);
        $check($doses->count());
        $rows = [];
        foreach ($doses as $d) {
            array_push($rows, ...$this->facts($d->visit_no.' / #'.$d->id, ['الخطة المرتبطة' => $d->plan_number, 'النسخة وقت الإعطاء' => $d->revision_number, 'تاريخ الإعطاء الفعلي' => $d->administered_on, 'الطبيب المشرف' => $d->supervising, 'القائم بالإعطاء' => $d->administering, 'عنوان الجلسة' => $d->session_label, 'ملاحظة' => $d->note, 'حالة الإعطاء' => $d->voided_at ? 'إعطاء مبطل — محفوظ تاريخيًا' : 'أُعطيت فعليًا', 'سبب إبطال الإعطاء' => $d->void_reason, 'تاريخ إبطال الإعطاء' => $d->voided_at], ['تاريخ الإعطاء الفعلي' => 'date', 'تاريخ إبطال الإعطاء' => 'datetime']));
        }
        $check(count($rows));
        $result[] = $this->section('جلسات الإعطاء الفعلية', $rows);
        $items = DB::table('dose_session_items as i')->join('dose_sessions as d', 'd.id', '=', 'i.dose_session_id')->leftJoin('funding_sources as f', 'f.id', '=', 'i.funding_source_id')->whereIn('d.id', $doses->pluck('id'))->when($individualVisit, fn ($q) => $q->whereNull('i.voided_at'))->orderBy('i.id')->limit($max + 1)->get(['i.*', 'd.administered_on', 'd.voided_at as parent_voided_at', 'd.void_reason as parent_void_reason', 'f.name_ar as funding']);
        $check($items->count());
        $rows = [];
        foreach ($items as $i) {
            array_push($rows, ...$this->facts('إعطاء #'.$i->dose_session_id, ['اسم محفوظ' => $i->medication_name_snapshot, 'كود محفوظ' => $i->medication_code_snapshot, 'التاريخ الفعلي' => $i->administered_on, 'قيمة الجرعة' => $i->dose_value, 'وحدة الجرعة' => $i->dose_unit, 'الكمية' => $i->quantity, 'وحدة الكمية' => $i->quantity_unit, 'طريق الإعطاء' => $i->route, 'تعليمات الجرعة' => $i->dose_text, 'التمويل' => $i->funding, 'ملاحظة' => $i->note, 'حالة الإعطاء الحاوي' => $i->parent_voided_at ? 'إعطاء مبطل — محفوظ تاريخيًا' : 'أُعطيت فعليًا', 'سبب إبطال الإعطاء' => $i->parent_void_reason, 'تاريخ إبطال الإعطاء' => $i->parent_voided_at, 'حالة البند' => $i->voided_at ? 'بند مبطل' : 'بند محفوظ', 'سبب إبطال البند' => $i->void_reason], ['تاريخ إبطال الإعطاء' => 'datetime', 'التاريخ الفعلي' => 'date', 'قيمة الجرعة' => 'decimal', 'الكمية' => 'decimal']));
        }
        $check(count($rows));
        $result[] = $this->section('تفاصيل الأدوية المعطاة', $rows);
        $dispensed = DB::table('visit_medications as m')->leftJoin('dose_sessions as parent', 'parent.id', '=', 'm.dose_session_id')->leftJoin('funding_sources as f', 'f.id', '=', 'm.funding_source_id')->leftJoin('staff as s', 's.id', '=', 'm.prescribing_staff_id')->where('m.facility_id', $f['id'])->whereIn('m.visit_id', $visitIds)->whereNotNull('m.dose_session_id')->when($individualVisit, fn ($q) => $q->whereNull('m.voided_at'))->orderBy('m.id')->limit($max + 1)->get(['m.*', 'parent.voided_at as parent_voided_at', 'f.name_ar as funding', 's.full_name as doctor']);
        $check($dispensed->count());
        $rows = [];
        foreach ($dispensed as $i) {
            array_push($rows, ...$this->facts('صرف #'.$i->id, ['اسم محفوظ' => $i->medication_name_snapshot, 'كود محفوظ' => $i->medication_code_snapshot, 'تاريخ الصرف الفعلي' => $i->dispensed_on, 'الغرض' => $i->dispensing_purpose === 'take_home' ? 'دواء مصروف ليؤخذ خارج المشفى' : 'دواء داعم مصروف مع العلاج', 'حالة الإعطاء المرتبط' => $i->parent_voided_at ? 'إعطاء مبطل — الصرف واقعة مستقلة' : 'إعطاء فعال', 'جلسة الإعطاء المرتبطة' => (string) $i->dose_session_id, 'الكمية' => $i->quantity, 'وحدة الكمية' => $i->quantity_unit, 'التعليمات' => $i->dose_text, 'التمويل' => $i->funding, 'الطبيب' => $i->doctor, 'ملاحظة' => $i->note, 'سبب الإبطال' => $i->void_reason], ['تاريخ الصرف الفعلي' => 'date', 'الكمية' => 'decimal']));
        }
        $check(count($rows));
        $result[] = $this->section('تفاصيل الصرف مع الجرعة', $rows);

        return $result;
    }
}
