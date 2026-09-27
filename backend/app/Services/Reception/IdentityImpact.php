<?php

namespace App\Services\Reception;

use Illuminate\Support\Facades\DB;

class IdentityImpact
{
    /** Inspect real FK dependencies, including newly added modules, without moving any rows. */
    public function references(string $parent, int $id, array $ignore = []): array
    {
        $keys = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->where('REFERENCED_TABLE_NAME', $parent)
            ->where('REFERENCED_COLUMN_NAME', 'id')->get(['TABLE_NAME', 'COLUMN_NAME']);
        $found = [];
        foreach ($keys as $key) {
            if (in_array($key->TABLE_NAME, $ignore, true)) {
                continue;
            }
            // Current locking read: an earlier permission read may have opened a
            // REPEATABLE READ snapshot before we waited for the identity lock.
            // Only existence is needed, never load/count clinical histories.
            $count = DB::table($key->TABLE_NAME)->where($key->COLUMN_NAME, $id)->limit(1)->lockForUpdate()->get([$key->COLUMN_NAME])->count();
            if ($count) {
                $found[$key->TABLE_NAME.':'.$key->COLUMN_NAME] = $count;
            }
        }
        ksort($found);

        return $found;
    }

    public function reasons(object $patient, object $dossier, bool $duplicate = false): array
    {
        $reasons = [];
        if ($patient->status !== 'active' || $dossier->status !== 'draft') {
            $reasons[] = 'حالة الهوية أو الملف تتطلب مراجعة.';
        }
        if (DB::table('patient_dossiers')->where('patient_id', $patient->id)->where('id', '!=', $dossier->id)->limit(1)->lockForUpdate()->get(['id'])->isNotEmpty()) {
            $reasons[] = 'للهوية سياق مستقل آخر؛ لا تُعرض بياناته هنا.';
        }
        if ($this->references('patients', $patient->id, ['patient_dossiers', 'visits', 'reception_identity_windows', 'patient_identity_corrections', 'patient_duplicate_reviews'])) {
            $reasons[] = 'توجد ارتباطات مستقلة بالهوية، بما فيها سجلات الدليل أو بنك الدم أو الاستيراد.';
        }
        if ($this->references('patient_dossiers', $dossier->id, ['visits', 'dossier_section_progress', 'reception_identity_windows', 'patient_identity_corrections'])) {
            $reasons[] = 'توجد ارتباطات محفوظة بالملف؛ يلزم فحصها دون نقلها.';
        }
        if (DB::table('dossier_section_progress')->where('dossier_id', $dossier->id)->whereNotIn('section', ['personal', 'visit'])->limit(1)->lockForUpdate()->get(['id'])->isNotEmpty()) {
            $reasons[] = 'بدأ استكمال أقسام مستقلة في الملف.';
        }
        foreach (['disability_text', 'clinical_history', 'previous_examinations', 'medication_source', 'other_organization', 'weight_kg', 'height_cm'] as $field) {
            if (! empty($dossier->$field)) {
                $reasons[] = 'يحتوي الملف معلومات محفوظة تتطلب مراجعة.';
                break;
            }
        }
        if ($dossier->is_oncology) {
            $reasons[] = 'الملف ورمي؛ لا يمكن التعامل معه كتسجيل فارغ.';
        }
        $visits = DB::table('visits')->where('patient_id', $patient->id)->lockForUpdate()->get();
        foreach ($visits as $visit) {
            if ($duplicate || $visit->id !== $dossier->registration_visit_id || $visit->status !== 'draft'
                || $visit->voided_at !== null || (int) $visit->lock_version !== 1
                || $this->references('visits', $visit->id, ['patient_dossiers', 'dossier_section_progress'])) {
                $reasons[] = 'توجد زيارة أو وقائع مرتبطة لا يجوز نقلها أو تصحيح هويتها مباشرة.';
                break;
            }
        }
        if (DB::table('audit_logs')->where('entity_type', 'dossier_report')->where('entity_id', $dossier->id)->limit(1)->lockForUpdate()->get(['id'])->isNotEmpty()) {
            $reasons[] = 'صدر تقرير عن الملف؛ يلزم قرار مراجعة للهوية.';
        }

        return array_values(array_unique($reasons));
    }
}
