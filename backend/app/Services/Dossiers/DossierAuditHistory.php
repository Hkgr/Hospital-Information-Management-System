<?php

namespace App\Services\Dossiers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DossierAuditHistory
{
    public const ENTITIES = [
        'patient_dossier' => 'بطاقة المريض', 'patient' => 'بيانات الشخص', 'dossier_medical' => 'المعلومات الطبية والورمية',
        'dossier_visit' => 'الزيارة', 'visit_diagnosis' => 'التشخيص', 'visit_services' => 'الخدمة',
        'visit_procedures' => 'الإجراء', 'visit_prescriptions' => 'الوصفة', 'visit_prescription_items' => 'بند الوصفة',
        'visit_outcomes' => 'النتيجة والإحالة', 'dossier_upload' => 'رفع المرفق', 'visit_attachment' => 'بيانات المرفق',
    ];

    public const ACTIONS = ['created' => 'إنشاء', 'updated' => 'تعديل', 'activated' => 'تفعيل', 'completed' => 'إكمال', 'reviewed' => 'مراجعة', 'voided' => 'إلغاء', 'started' => 'بدء رفع', 'uploaded' => 'رفع', 'cancelled' => 'إلغاء رفع'];

    /** Resolve ownership from authoritative FK-linked rows, never from audit JSON. */
    private function subjects(array $f, array $d, ?int $visit): Builder
    {
        $root = fn (string $type, string $id) => DB::table('patient_dossiers as d')->where('d.id', $d['id'])->where('d.facility_id', $f['id'])
            ->when($visit, fn ($q) => $q->whereRaw('1 = 0'))->selectRaw('? AS entity_type, '.$id.' AS entity_id, NULL AS visit_id', [$type]);
        $subjects = $root('patient_dossier', 'd.id')->unionAll($root('patient', 'd.patient_id'))->unionAll($root('dossier_medical', 'd.id'));
        $visits = fn () => DB::table('visits as v')->where('v.dossier_id', $d['id'])->where('v.patient_id', $d['patient_id'])->where('v.facility_id', $f['id'])->when($visit, fn ($q) => $q->where('v.id', $visit));
        $subjects->unionAll($visits()->selectRaw('? AS entity_type, v.id AS entity_id, v.id AS visit_id', ['dossier_visit']));
        $tables = ['visit_diagnosis' => 'visit_diagnoses', 'visit_services' => 'visit_services', 'visit_procedures' => 'visit_procedures', 'visit_prescriptions' => 'visit_prescriptions', 'visit_outcomes' => 'visit_outcomes'];
        if ($f['capabilities']['attachments_view']) {
            $tables += ['dossier_upload' => 'visit_attachment_uploads', 'visit_attachment' => 'visit_attachments'];
        }
        foreach ($tables as $type => $table) {
            $subjects->unionAll($visits()->join($table.' as e', 'e.visit_id', '=', 'v.id')->where('e.facility_id', $f['id'])
                ->selectRaw('? AS entity_type, e.id AS entity_id, v.id AS visit_id', [$type]));
        }
        $subjects->unionAll($visits()->join('visit_prescriptions as p', 'p.visit_id', '=', 'v.id')->join('visit_prescription_items as e', 'e.prescription_id', '=', 'p.id')
            ->where('p.facility_id', $f['id'])->where('e.facility_id', $f['id'])->selectRaw('? AS entity_type, e.id AS entity_id, v.id AS visit_id', ['visit_prescription_items']));

        return $subjects;
    }

    public function query(array $f, int $dossier, array $filters): Builder
    {
        $d = app(DossierWrites::class)->dossier($f, $dossier, false);
        $visit = $filters['visit_id'] ?? null;
        if ($visit) {
            abort_unless(DB::table('visits')->where('id', $visit)->where('dossier_id', $dossier)->where('patient_id', $d['patient_id'])->where('facility_id', $f['id'])->exists(), 404);
        }
        $q = DB::table('audit_logs as a')->joinSub($this->subjects($f, $d, $visit), 'subject', function ($join) {
            $join->on('subject.entity_type', '=', 'a.entity_type')->on('subject.entity_id', '=', 'a.entity_id');
        })->where('a.facility_id', $f['id']);
        // Audit timestamps are stored in the application's timezone; filter by facility-local days.
        foreach (['from' => '>=', 'to' => '<'] as $field => $operator) {
            if (! empty($filters[$field])) {
                $at = CarbonImmutable::parse($filters[$field], $f['timezone']);
                $q->where('a.occurred_at', $operator, ($field === 'to' ? $at->addDay() : $at)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
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
            if ($action !== 'voided') {
                $q->whereNull('a.new_values->voided_at');
            }
        }

        return $q;
    }

    public function listing(array $f, int $dossier, array $filters): array
    {
        return DB::transaction(function () use ($f, $dossier, $filters) {
            $q = $this->query($f, $dossier, $filters);
            $total = (clone $q)->count();
            $page = (int) ($filters['page'] ?? 1);
            $size = (int) ($filters['per_page'] ?? 10);
            $rows = $q->orderByDesc('a.occurred_at')->orderByDesc('a.id')->forPage($page, $size)
                ->get(['a.id', 'a.actor_id', 'a.entity_type', 'a.entity_id', 'a.event', 'a.occurred_at', 'a.reason', 'a.old_values', 'a.new_values', 'subject.visit_id']);
            // Resolve page-only metadata in batches; no user-directory search endpoint is exposed.
            $actors = DB::table('users')->whereIn('id', $rows->pluck('actor_id'))->pluck('name', 'id');
            $visits = DB::table('visits')->where('facility_id', $f['id'])->where('dossier_id', $dossier)->whereIn('id', $rows->pluck('visit_id')->filter())->get(['id', 'visit_no', 'visit_date'])->keyBy('id');
            $presenter = new DossierAuditValues($f, $rows);
            $data = $rows->map(function ($row) use ($f, $actors, $visits, $presenter) {
                $old = json_decode($row->old_values ?? '{}', true) ?: [];
                $new = json_decode($row->new_values ?? '{}', true) ?: [];
                $void = ! empty($new['voided_at']);
                $action = $void ? 'voided' : ($row->event === 'saved' ? ($row->old_values === null ? 'created' : 'updated') : $row->event);
                $label = self::ACTIONS[$action] ?? 'تغيير مسجل';

                return ['id' => $row->id, 'occurred_at' => CarbonImmutable::parse($row->occurred_at, config('app.timezone'))->setTimezone($f['timezone'])->toIso8601String(),
                    'actor' => ['id' => $row->actor_id, 'name' => $actors[$row->actor_id] ?? 'مستخدم غير متاح'],
                    'visit' => $row->visit_id ? ($visits[$row->visit_id] ?? null) : null,
                    'entity' => $row->entity_type, 'entity_label' => self::ENTITIES[$row->entity_type], 'entity_id' => $row->entity_id,
                    'action' => $action, 'action_label' => $label, 'changes' => $presenter->changes($row->entity_type, $old, $new),
                    'reason' => $row->reason ?: (is_string($new['void_reason'] ?? null) ? $new['void_reason'] : null)];
            })->all();
            $entities = self::ENTITIES;
            if (! $f['capabilities']['attachments_view']) {
                unset($entities['dossier_upload'], $entities['visit_attachment']);
            }

            return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $size, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $size))],
                'filters' => ['entities' => $entities, 'actions' => self::ACTIONS], 'timezone' => $f['timezone']];
        });
    }
}
