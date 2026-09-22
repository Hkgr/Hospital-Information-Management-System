<?php

namespace App\Services\Audit;

use App\Services\Auth\UserAccessContext;
use App\Services\Dossiers\DossierAuditHistory;
use App\Services\Dossiers\DossierAuditValues;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class SystemLogHistory
{
    public const CATEGORIES = [
        'patient_card' => 'بطاقة المريض',
        'treatment' => 'العلاج والأدوية',
        'directory' => 'الدليل',
        'stock' => 'المخزون',
        'blood_bank' => 'بنك الدم',
        'accounts' => 'الحسابات',
        'technical' => 'خطأ تقني',
        'other' => 'أخرى',
    ];

    public const ENTITIES = [
        'patient_dossier' => 'بطاقة المريض', 'patient' => 'بيانات الشخص', 'dossier_medical' => 'المعلومات الطبية والورمية',
        'dossier_visit' => 'الزيارة', 'visit_diagnosis' => 'التشخيص', 'visit_services' => 'الخدمة',
        'visit_procedures' => 'الإجراء', 'visit_prescriptions' => 'الوصفة', 'visit_prescription_items' => 'بند الوصفة',
        'visit_outcomes' => 'النتيجة والإحالة', 'dossier_upload' => 'رفع المرفق', 'visit_attachment' => 'بيانات المرفق',
        'dossier_import_batch' => 'دفعة الاستيراد', 'dossier_report' => 'تقرير البطاقة', 'diagnosis' => 'تشخيص الدليل',
        'medication' => 'دواء الدليل', 'service' => 'خدمة الدليل', 'procedure' => 'إجراء الدليل',
        'oncology_plans' => 'الخطة العلاجية', 'oncology_plan_revisions' => 'نسخة الخطة', 'oncology_sessions' => 'الموعد العلاجي',
        'oncology_session_doses' => 'الجرعة العلاجية', 'dose_sessions' => 'الإعطاء الفعلي', 'dose_session_items' => 'الدواء المعطى',
        'visit_medications' => 'صرف الدواء', 'visit_pathologies' => 'التشريح المرضي', 'visit_diagnostic_assessments' => 'التقييم التشخيصي',
        'clinic' => 'العيادة', 'doctor' => 'الطبيب', 'medication_store' => 'مخزن الأدوية', 'medication_supplier' => 'المورّد',
        'medication_receipt' => 'إيصال المخزون', 'blood_bank' => 'بنك الدم', 'blood_bank_event' => 'واقعة بنك الدم',
        'blood_bank_person' => 'شخص بنك الدم', 'auth_session' => 'جلسة الدخول', 'system_error' => 'خطأ تقني',
    ];

    public const ACTIONS = [
        'created' => 'إنشاء', 'updated' => 'تعديل', 'saved' => 'حفظ', 'activated' => 'تفعيل', 'completed' => 'إكمال',
        'reviewed' => 'مراجعة', 'voided' => 'إلغاء', 'started' => 'بدء رفع', 'uploaded' => 'رفع', 'cancelled' => 'إلغاء رفع',
        'imported' => 'اعتماد استيراد', 'login' => 'تسجيل دخول', 'logout' => 'تسجيل خروج', 'failed' => 'خطأ تقني',
        'corrected' => 'تصحيح', 'archived' => 'أرشفة', 'restored' => 'استعادة', 'deactivated' => 'تعطيل',
        'reactivated' => 'إعادة تفعيل', 'deleted' => 'حذف', 'confirmed' => 'تأكيد', 'exported' => 'تصدير',
        'clinics_changed' => 'تغيير العيادات', 'duplicate_confirmed' => 'تأكيد تكرار', 'attachment_linked' => 'ربط مرفق',
        'uploaded_file' => 'رفع ملف', 'match_reviewed' => 'مراجعة مطابقة', 'duplicate_skipped' => 'تجاوز تكرار',
        'bundle_needs_review' => 'دفعة تحتاج مراجعة', 'state_changed' => 'تغيير الحالة', 'source_expired' => 'انتهاء مصدر',
    ];

    private const CATEGORY_OF = [
        'patient_dossier' => 'patient_card', 'patient' => 'patient_card', 'dossier_medical' => 'patient_card',
        'dossier_visit' => 'patient_card', 'visit_diagnosis' => 'patient_card', 'visit_services' => 'patient_card',
        'visit_procedures' => 'patient_card', 'visit_prescriptions' => 'patient_card', 'visit_prescription_items' => 'patient_card',
        'visit_outcomes' => 'patient_card', 'dossier_upload' => 'patient_card', 'visit_attachment' => 'patient_card',
        'dossier_import_batch' => 'patient_card', 'dossier_report' => 'patient_card', 'visit_pathologies' => 'patient_card',
        'visit_diagnostic_assessments' => 'patient_card',
        'oncology_plans' => 'treatment', 'oncology_plan_revisions' => 'treatment', 'oncology_sessions' => 'treatment',
        'oncology_session_doses' => 'treatment', 'dose_sessions' => 'treatment', 'dose_session_items' => 'treatment',
        'visit_medications' => 'treatment',
        'clinic' => 'directory', 'doctor' => 'directory', 'service' => 'directory', 'procedure' => 'directory',
        'medication' => 'directory', 'diagnosis' => 'directory',
        'medication_store' => 'stock', 'medication_supplier' => 'stock', 'medication_receipt' => 'stock',
        'blood_bank' => 'blood_bank', 'blood_bank_event' => 'blood_bank', 'blood_bank_person' => 'blood_bank',
        'auth_session' => 'accounts', 'system_error' => 'technical',
    ];

    public function facility(\App\Models\User $user, int $id): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id) {
                return $entry['facility'] + ['permissions' => $entry['permissions']];
            }
        }
        throw new HttpResponseException(response()->json(['error' => ['code' => 'FACILITY_ACCESS_DENIED', 'message' => 'ليس لديك وصول إلى المنشأة المطلوبة.']], 403));
    }

    public function listing(array $f, array $filters): array
    {
        return DB::transaction(function () use ($f, $filters) {
            $q = $this->query($f, $filters);
            $total = (clone $q)->count();
            $page = (int) ($filters['page'] ?? 1);
            $size = (int) ($filters['per_page'] ?? 10);
            $rows = $q->orderByDesc('a.occurred_at')->orderByDesc('a.id')->forPage($page, $size)
                ->get(['a.id', 'a.actor_id', 'a.entity_type', 'a.entity_id', 'a.event', 'a.occurred_at', 'a.reason', 'a.old_values', 'a.new_values']);
            $actors = DB::table('users')->whereIn('id', $rows->pluck('actor_id')->filter())->pluck('name', 'id');
            $presenter = new DossierAuditValues($f, $rows);
            $data = $rows->map(function ($row) use ($f, $actors, $presenter) {
                $old = json_decode($row->old_values ?? '{}', true) ?: [];
                $new = json_decode($row->new_values ?? '{}', true) ?: [];
                $void = ! empty($new['voided_at']);
                $action = $void ? 'voided' : ($row->event === 'saved' ? ($row->old_values === null ? 'created' : 'updated') : $row->event);
                $category = self::CATEGORY_OF[$row->entity_type] ?? 'other';
                $changes = $presenter->changes($row->entity_type, $old, $new);
                if ($changes === [] && in_array($row->entity_type, ['system_error', 'auth_session'], true)) {
                    $changes = $this->safeFacts($row->entity_type, $new);
                }

                return [
                    'id' => $row->id,
                    'occurred_at' => CarbonImmutable::parse($row->occurred_at, config('app.timezone'))->setTimezone($f['timezone'])->toIso8601String(),
                    'actor' => ['id' => $row->actor_id, 'name' => $actors[$row->actor_id] ?? 'مستخدم غير متاح'],
                    'category' => $category, 'category_label' => self::CATEGORIES[$category],
                    'entity' => $row->entity_type, 'entity_label' => self::ENTITIES[$row->entity_type] ?? $row->entity_type,
                    'entity_id' => $row->entity_id, 'action' => $action,
                    'action_label' => self::ACTIONS[$action] ?? (DossierAuditHistory::ACTIONS[$action] ?? 'حركة مسجلة'),
                    'changes' => $changes,
                    'reason' => $row->reason ?: (is_string($new['void_reason'] ?? null) ? $new['void_reason'] : null),
                ];
            })->all();

            return [
                'data' => $data,
                'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $size))],
                'filters' => ['categories' => self::CATEGORIES, 'entities' => self::ENTITIES, 'actions' => self::ACTIONS],
                'timezone' => $f['timezone'],
            ];
        });
    }

    private function query(array $f, array $filters): Builder
    {
        $q = DB::table('audit_logs as a')->where('a.facility_id', $f['id']);
        foreach (['from' => '>=', 'to' => '<'] as $field => $operator) {
            if (! empty($filters[$field])) {
                $at = CarbonImmutable::parse($filters[$field], $f['timezone']);
                $q->where('a.occurred_at', $operator, ($field === 'to' ? $at->addDay() : $at)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
            }
        }
        if (! empty($filters['category'])) {
            $types = array_keys(array_filter(self::CATEGORY_OF, fn ($category) => $category === $filters['category']));
            if ($filters['category'] === 'other') {
                $q->whereNotIn('a.entity_type', array_keys(self::CATEGORY_OF));
            } else {
                $q->whereIn('a.entity_type', $types ?: ['__none__']);
            }
        }
        if (! empty($filters['entity'])) {
            $q->where('a.entity_type', $filters['entity']);
        }
        if (! empty($filters['action'])) {
            $action = $filters['action'];
            $q->where(function ($q) use ($action) {
                $q->where('a.event', $action);
                if ($action === 'voided') {
                    $q->orWhereNotNull('a.new_values->voided_at');
                } elseif (in_array($action, ['created', 'updated'], true)) {
                    $q->orWhere(fn ($q) => $q->where('a.event', 'saved')->whereNull('a.old_values', 'and', $action === 'updated'));
                }
            });
        }

        return $q;
    }

    private function safeFacts(string $type, array $new): array
    {
        $allowed = $type === 'system_error' ? ['message' => 'الرسالة', 'type' => 'التصنيف التقني', 'path' => 'المسار', 'method' => 'الطريقة']
            : ['username' => 'اسم المستخدم'];
        $rows = [];
        foreach ($allowed as $field => $label) {
            if (! array_key_exists($field, $new) || ! is_scalar($new[$field])) {
                continue;
            }
            $rows[] = ['field' => $field, 'label' => $label, 'before' => null, 'after' => (string) $new[$field], 'before_recorded' => false];
        }

        return $rows;
    }
}
