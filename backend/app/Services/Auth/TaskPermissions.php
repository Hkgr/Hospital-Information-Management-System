<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;

/** One-way compatibility: a new task never implies its old umbrella permission. */
class TaskPermissions
{
    public const TASKS = [
        // code => [Arabic name, scope, prerequisites, alternative legacy grants (AND within each)]
        'roles.delegate' => ['إدارة التفويض دون امتلاك العمل الطبي', 'global', ['roles.view', 'roles.update'], []],
        'users.global.view' => ['عرض التفويضات العالمية للحساب', 'global', ['users.view'], []],
        'users.global.manage' => ['تعديل التفويضات العالمية للحساب', 'global', ['users.global.view', 'roles.delegate'], []],
        'users.roles.assign' => ['إسناد الأدوار داخل المشفى', 'facility', ['users.view', 'roles.view'], []],
        'patients.basic.view' => ['عرض بيانات المريض الأساسية — داخل المشفى', 'facility', [], [['reception.view'], ['dossiers.view']]],
        'patients.basic.search' => ['البحث المحدود عن المريض — تفويض عالمي', 'global', [], [['reception.patients.search'], ['patients.search']]],
        'patients.basic.create' => ['إنشاء هوية مريض جديد — تفويض عالمي', 'global', [], [['reception.patients.create'], ['patients.create']]],
        'patient_cards.register' => ['فتح بطاقة وتسجيل الزيارة الأولى', 'facility', ['patients.basic.view'], [['reception.register'], ['dossiers.create', 'dossiers.visits.create']]],
        'patients.own.correct' => ['تصحيح بيانات أدخلتها خلال المهلة', 'allowed_records', ['patients.basic.view'], [['reception.correct']]],
        'patients.corrections.request' => ['طلب تصحيح هوية المريض', 'allowed_records', ['patients.basic.view'], [['reception.corrections.request']]],
        'dossiers.medical.view' => ['عرض التاريخ الطبي للبطاقة', 'facility', [], [['dossiers.view']]],
        'dossiers.visits.view' => ['عرض الزيارات المحفوظة', 'facility', ['dossiers.medical.view'], [['dossiers.view']]],
        'dossiers.visits.draft.update' => ['تعديل بيانات زيارة مسودة', 'facility', ['dossiers.medical.view', 'dossiers.visits.view'], [['dossiers.visits.update']]],
        'dossiers.diagnoses.update' => ['إدخال وتصحيح تشخيصات الزيارة', 'facility', ['dossiers.medical.view', 'dossiers.visits.view'], [['dossiers.visits.update']]],
        'dossiers.services.update' => ['إدخال وتصحيح خدمات الزيارة', 'facility', ['dossiers.medical.view', 'dossiers.visits.view'], [['dossiers.clinical.update']]],
        'dossiers.procedures.update' => ['إدخال وتصحيح إجراءات الزيارة', 'facility', ['dossiers.medical.view', 'dossiers.visits.view'], [['dossiers.clinical.update']]],
        'dossiers.prescriptions.update' => ['إدخال وتصحيح وصفة الزيارة', 'facility', ['dossiers.medical.view', 'dossiers.visits.view'], [['dossiers.clinical.update']]],
        'dossiers.outcomes.update' => ['إدخال وتصحيح نتيجة الزيارة', 'facility', ['dossiers.medical.view', 'dossiers.visits.view'], [['dossiers.clinical.update']]],
        'dossiers.treatment.schedule.create' => ['إضافة موعد علاج', 'facility', ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.treatment.view'], [['dossiers.treatment.schedule']]],
        'dossiers.treatment.schedule.update' => ['تصحيح موعد علاج', 'facility', ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.treatment.view'], [['dossiers.treatment.schedule']]],
        'dossiers.treatment.administration.correct' => ['تصحيح إعطاء جرعة', 'facility', ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.treatment.view'], [['dossiers.treatment.correct']]],
        'dossiers.treatment.administration.void' => ['إلغاء إعطاء جرعة بسبب موثق', 'facility', ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.treatment.view', 'dossiers.treatment.schedule.update'], [['dossiers.treatment.void']]],
        'dossiers.treatment.dispensing.correct' => ['تصحيح صرف دواء', 'facility', ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.treatment.view'], [['dossiers.treatment.correct']]],
        'dossiers.treatment.dispensing.void' => ['إلغاء صرف دواء بسبب موثق', 'facility', ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.treatment.view'], [['dossiers.treatment.void']]],
        'blood_bank.issue.create' => ['تسجيل صرف مكوّن دم', 'facility', ['blood_bank.view'], [['blood_bank.benefits.create']]],
        'blood_bank.issue.update' => ['تصحيح صرف مكوّن دم', 'facility', ['blood_bank.view'], [['blood_bank.benefits.update']]],
        'blood_bank.transfusion.create' => ['تسجيل نقل دم فعلي', 'facility', ['blood_bank.view'], [['blood_bank.benefits.create']]],
        'blood_bank.transfusion.update' => ['تصحيح نقل دم فعلي', 'facility', ['blood_bank.view'], [['blood_bank.benefits.update']]],
        'catalog.directory.edit' => ['تعديل تعريف في الدليل المشترك', 'global', [], [['catalog.directory.update']]],
        'catalog.directory.deactivate' => ['تعطيل تعريف في الدليل المشترك', 'global', [], [['catalog.directory.update']]],
        'catalog.directory.restore' => ['استعادة تعريف مؤرشف كغير فعال', 'global', [], [['catalog.directory.update']]],
        'catalog.directory.reactivate' => ['إعادة تفعيل تعريف في الدليل', 'global', [], [['catalog.directory.update']]],
        'catalog.directory.archive' => ['أرشفة تعريف مع حفظ تاريخه', 'global', [], [['catalog.directory.delete']]],
        'catalog.directory.destroy' => ['حذف تعريف غير مستخدم نهائيًا', 'global', [], [['catalog.directory.delete']]],
        'stock.receipts.write' => ['إعداد مسودة استلام مخزون', 'facility', ['stock.view'], [['stock.receive']]],
        'stock.receipts.confirm' => ['تأكيد استلام المخزون', 'facility', ['stock.view'], [['stock.receive']]],
        'stock.suppliers.write' => ['إدارة دليل الموردين', 'facility', ['stock.view'], [['stock.suppliers.manage']]],
        'stock.stores.write' => ['إدارة المستودعات', 'facility', ['stock.view'], [['stock.suppliers.manage']]],
        'clinics.edit' => ['تعديل تعريف العيادة', 'facility', ['clinics.view'], [['clinics.update']]],
        'clinics.deactivate' => ['تعطيل العيادة', 'facility', ['clinics.view'], [['clinics.update']]],
        'clinics.restore' => ['استعادة عيادة مؤرشفة', 'facility', ['clinics.view'], [['clinics.update']]],
        'clinics.reactivate' => ['إعادة تفعيل العيادة', 'facility', ['clinics.view'], [['clinics.update']]],
        'clinics.archive' => ['أرشفة العيادة مع حفظ تاريخها', 'facility', ['clinics.view'], [['clinics.delete']]],
        'clinics.destroy' => ['حذف عيادة غير مستخدمة', 'facility', ['clinics.view'], [['clinics.delete']]],
        'doctors.directory.edit' => ['تعديل تعريف الطبيب المشترك', 'global', [], [['doctors.directory.update']]],
        'doctors.directory.deactivate' => ['تعطيل الطبيب في الدليل', 'global', [], [['doctors.directory.update']]],
        'doctors.directory.restore' => ['استعادة طبيب مؤرشف', 'global', [], [['doctors.directory.update']]],
        'doctors.directory.reactivate' => ['إعادة تفعيل الطبيب', 'global', [], [['doctors.directory.update']]],
        'doctors.directory.archive' => ['أرشفة الطبيب مع حفظ تاريخه', 'global', [], [['doctors.directory.delete']]],
        'doctors.directory.destroy' => ['حذف طبيب غير مستخدم', 'global', [], [['doctors.directory.delete']]],
    ];

    /** Expand only active targets, from original grants, within the same authorization scope. */
    public function effective(array $grants, bool $global = false, ?array $active = null): array
    {
        $active ??= $this->activeTasks();
        $result = $grants;
        foreach (self::TASKS as $code => [, $scope, , $alternatives]) {
            if (($scope === 'global') !== $global || ! in_array($code, $active, true)) {
                continue;
            }
            foreach ($alternatives as $required) {
                if (array_diff($required, $grants) === []) {
                    $result[] = $code;
                    break;
                }
            }
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);

        return $result;
    }

    public function activeTasks(): array
    {
        return DB::table('permissions')->where('is_active', true)->whereIn('code', array_keys(self::TASKS))->pluck('code')->all();
    }

    public function describe(string $code, string $name): array
    {
        $name = str_replace('الاستقبال', 'التسجيل الأساسي', $name);
        $task = self::TASKS[$code] ?? null;
        $global = str_contains($code, '.directory.') || in_array($code, ['patients.search', 'patients.create', 'patients.update', 'patients.identity.review', 'patients.duplicates.merge', 'diagnoses.create', 'medications.create', 'blood_bank.patients.search', 'reception.patients.search', 'reception.patients.create'], true);
        $scope = $task[1] ?? ($global ? 'global' : 'facility');
        $required = $task[2] ?? [];
        if (! $task && ! $global) {
            $root = explode('.', $code)[0];
            $view = $root === 'dossiers' ? 'dossiers.medical.view' : "$root.view";
            if ($code !== $view && $code !== 'dossiers.view' && in_array($root, ['dossiers', 'catalog', 'blood_bank', 'clinics', 'doctors', 'stock', 'users', 'roles', 'settings', 'statistics', 'reception'], true)) {
                $required[] = $view;
            }
            if ($root === 'dossiers' && ! in_array($code, ['dossiers.view', 'dossiers.audit', 'dossiers.delete'], true)) {
                $required[] = 'dossiers.visits.view';
            }
        }
        $legacy = [];
        foreach (self::TASKS as $new => $definition) {
            foreach ($definition[3] as $alternative) {
                if (in_array($code, $alternative, true)) {
                    $legacy[] = $new;
                }
            }
        }

        $reason = $code === 'dossiers.treatment.administration.void'
            ? ' يتطلب صلاحية تصحيح موعد علاج لأن الإلغاء يتضمن معالجة حالة الجلسة المرتبطة.'
            : '';

        return ['name_ar' => $task[0] ?? $name, 'description' => ($task[0] ?? $name).'؛ '.($scope === 'global' ? 'تفويض عالمي مستقل، ولا يمنح الوصول إلى بيانات منشأة غير مصرح بها.' : ($scope === 'allowed_records' ? 'للسجلات المسموحة فقط مع استمرار قيود الملكية والمهلة والمراجعة.' : 'داخل المشفى المصرح به فقط، مع استمرار فحوص الحالة والنسخة.')).$reason, 'scope' => $scope, 'prerequisites' => $required, 'legacy_tasks' => array_values(array_unique($legacy))];
    }
}
