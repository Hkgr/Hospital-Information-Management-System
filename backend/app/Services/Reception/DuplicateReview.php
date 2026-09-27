<?php

namespace App\Services\Reception;

use App\Services\Dossiers\DossierWrites;
use App\Services\Dossiers\PatientCardCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DuplicateReview
{
    public function preview(array $f, int $canonical, int $duplicate): array
    {
        return DB::transaction(function () use ($f, $canonical, $duplicate) {
            app(PatientCardCodes::class)->reserve();
            $ids = [$canonical, $duplicate];
            sort($ids);
            $rows = [];
            foreach ($ids as $id) {
                $rows[$id] = app(IdentityCorrections::class)->rows($f, $id);
            }
            [$p, $d] = $rows[$canonical];
            [$other, $context] = $rows[$duplicate];
            abort_if($p->id === $other->id, 422, 'اختر هويتين مختلفتين.');
            $reasons = app(IdentityImpact::class)->reasons($other, $context, true);
            if ($p->status !== 'active' || DB::table('patient_dossiers')->where('patient_id', $p->id)->where('facility_id', '!=', $f['id'])->limit(1)->lockForUpdate()->get(['id'])->isNotEmpty()) {
                $reasons[] = 'السجل المعتمد مرتبط بسياق آخر أو غير متاح؛ يلزم فحص مستقل.';
            }
            foreach ([...IdentityCorrections::FIELDS, 'governorate_id', 'city_id', 'paper_file_number', 'displacement_status', 'permanent_address', 'marital_status', 'occupation', 'smoking_status', 'alcohol_status'] as $field) {
                if (($other->$field ?? null) !== null && ($other->$field ?? '') !== '' && (string) $other->$field !== (string) ($p->$field ?? '')) {
                    $reasons[] = 'تختلف بيانات الهوية؛ يجب مراجعة التصحيح أولًا دون دمج تلقائي.';
                    break;
                }
            }
            if (DB::table('patient_identity_corrections')->where('patient_id', $other->id)->where('status', 'pending')->limit(1)->lockForUpdate()->get(['id'])->isNotEmpty()) {
                $reasons[] = 'يوجد طلب تصحيح معلق للهوية المكررة.';
            }

            return ['canonical_dossier_id' => $d->id, 'duplicate_dossier_id' => $context->id,
                'canonical_patient_id' => $p->id, 'duplicate_patient_id' => $other->id,
                'canonical_code' => $p->patient_code, 'duplicate_code' => $other->patient_code,
                'canonical_version' => $p->lock_version, 'duplicate_version' => $other->lock_version,
                'canonical_dossier_version' => $d->lock_version, 'duplicate_dossier_version' => $context->lock_version,
                'can_merge' => $reasons === [], 'blockers' => array_values(array_unique($reasons)),
                'effect' => 'ربط الهوية الفارغة المكررة بالمعتمدة مع حفظ سجلها وكودها. لا نقل أو حذف للزيارات أو العلاج أو المرفقات أو التدقيق.'];
        });
    }

    public function request(Request $r, array $f): int
    {
        $input = $r->validate(['canonical_dossier_id' => 'required|integer|min:1', 'duplicate_dossier_id' => 'required|integer|min:1', 'preview_hash' => 'required|string|size:64', 'request_id' => 'required|uuid', 'reason' => 'required|string|max:255']);

        return app(DossierWrites::class)->once($r, $f, $input, 'duplicates:request', function () use ($r, $f, $input) {
            app(ReviewAccess::class)->facility($r, 'patient_duplicates.review');
            $preview = $this->preview($f, $input['canonical_dossier_id'], $input['duplicate_dossier_id']);
            if ($this->hash($preview) !== $input['preview_hash']) {
                ReviewAccess::conflict('تغيّر أثر العملية؛ أعد المعاينة أولًا.');
            }
            $id = DB::table('patient_duplicate_reviews')->insertGetId(['facility_id' => $f['id'], 'canonical_patient_id' => $preview['canonical_patient_id'], 'duplicate_patient_id' => $preview['duplicate_patient_id'], 'requested_by' => $r->user()->id, 'preview' => json_encode($preview), 'reason' => $input['reason'], 'created_at' => now(), 'updated_at' => now()]);
            app(IdentityCorrections::class)->audit($r, $f, 'patient_duplicate_review', $id, 'created', null, ['reason' => $input['reason'], 'can_merge' => $preview['can_merge']]);

            return $id;
        });
    }

    public function hash(array $preview): string
    {
        return hash('sha256', json_encode($preview));
    }

    public function detail(array $f, int $id): array
    {
        $q = DB::table('patient_duplicate_reviews')->where('id', $id)->where('facility_id', $f['id'])->first();
        abort_unless($q, 404);
        $saved = json_decode($q->preview, true);

        return (array) $q + ['requester' => DB::table('users')->where('id', $q->requested_by)->value('name'), 'current_preview' => $this->preview($f, $saved['canonical_dossier_id'], $saved['duplicate_dossier_id'])];
    }

    public function decide(Request $r, array $f, int $id): int
    {
        $input = $r->validate(['request_id' => 'required|uuid', 'lock_version' => 'required|integer|min:1', 'decision' => 'required|in:approved,rejected', 'reason' => 'required|string|max:255']);
        if ($input['decision'] === 'approved') {
            app(ReviewAccess::class)->global($r, 'patients.duplicates.merge');
        }

        return app(DossierWrites::class)->once($r, $f, $input, 'duplicates:decision:'.$id, function () use ($r, $f, $id, $input) {
            app(ReviewAccess::class)->facility($r, 'patient_duplicates.review');
            if ($input['decision'] === 'approved') {
                app(ReviewAccess::class)->global($r, 'patients.duplicates.merge');
            }
            app(PatientCardCodes::class)->reserve();
            $q = DB::table('patient_duplicate_reviews')->where('id', $id)->where('facility_id', $f['id'])->lockForUpdate()->first();
            abort_unless($q, 404);
            if ($q->status !== 'pending' || (int) $q->lock_version !== $input['lock_version']) {
                ReviewAccess::conflict('سبق اتخاذ قرار أو تغيّرت نسخة الطلب.');
            }
            if ($input['decision'] === 'approved') {
                $saved = json_decode($q->preview, true);
                $current = $this->preview($f, $saved['canonical_dossier_id'], $saved['duplicate_dossier_id']);
                if ($current !== $saved) {
                    ReviewAccess::conflict('تغيّرت البيانات أو الارتباطات. أعد المعاينة وقدم طلبًا جديدًا.');
                }
                if (! $current['can_merge']) {
                    ReviewAccess::conflict('الدمج ممنوع: '.implode(' ', $current['blockers']), 'DUPLICATE_MERGE_BLOCKED');
                }
                // Native schema already defines merged identity + restrictive self FK.
                // No independent record is moved, rewritten or deleted.
                DB::table('patients')->where('id', $q->duplicate_patient_id)->update(['status' => 'merged', 'merged_into_id' => $q->canonical_patient_id, 'lock_version' => $current['duplicate_version'] + 1, 'updated_at' => now()]);
                DB::table('patients')->where('id', $q->canonical_patient_id)->increment('lock_version');
                app(IdentityCorrections::class)->audit($r, $f, 'patient', $q->duplicate_patient_id, 'merged', ['status' => 'active'], ['status' => 'merged', 'merged_into_id' => $q->canonical_patient_id, 'reason' => $input['reason']]);
            }
            DB::table('patient_duplicate_reviews')->where('id', $id)->update(['status' => $input['decision'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'decision_reason' => $input['reason'], 'lock_version' => $q->lock_version + 1, 'updated_at' => now()]);
            app(IdentityCorrections::class)->audit($r, $f, 'patient_duplicate_review', $id, $input['decision'], ['status' => 'pending'], ['status' => $input['decision'], 'reason' => $input['reason']]);

            return $id;
        });
    }
}
