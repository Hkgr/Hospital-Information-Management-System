<?php

namespace App\Services\Dossiers;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OncologyWriter
{
    public function __construct(private DossierWrites $writes, private DossierClinicalContext $context, private OncologyQueries $queries) {}

    public function persist(Request $r, array $f, string $table, ?object $old, array $fields): int
    {
        $fields += ['updated_by' => $r->user()->id, 'updated_at' => now(), 'lock_version' => ($old?->lock_version ?? 0) + 1];
        if ($old) {
            $id = $old->id;
            DB::table($table)->where('id', $id)->update($fields);
        } else {
            $id = DB::table($table)->insertGetId($fields + ['entered_by' => $r->user()->id, 'created_at' => now()]);
        }
        $this->writes->audit($r, $f, $table, $id, $old ? (array) $old : null, (array) DB::table($table)->where('id', $id)->first());

        return $id;
    }

    private function plan(array $f, int $dossier, int $id, ?int $version = null): object
    {
        $this->writes->dossier($f, $dossier);
        $plan = DB::table('oncology_plans')->where('id', $id)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->lockForUpdate()->first();
        abort_unless($plan, 404);
        if ($version !== null) {
            DossierWrites::version((array) $plan, $version);
        }

        return $plan;
    }

    public function savePlan(Request $r, array $f, int $dossier, array $data, ?int $id): int
    {
        return $this->writes->once($r, $f, $data, "oncology:plan:$dossier:".($id ?? 'new'), function () use ($r, $f, $dossier, $data, $id) {
            $this->context->lock([[$data['protocol_doctor_id'], $data['treating_doctor_id']], [$data['protocol_clinic_id'], $data['treating_clinic_id']]]);
            $d = $this->writes->dossier($f, $dossier);
            $old = $id ? $this->plan($f, $dossier, $id, $data['lock_version']) : null;
            $priorRevision = $old ? DB::table('oncology_plan_revisions')->where('id', $old->current_revision_id)->first() : null;
            $this->context->check($f, $data['protocol_clinic_id'], $data['protocol_doctor_id'], $f['today'], 'protocol_doctor_id', false);
            $this->context->check($f, $data['treating_clinic_id'], $data['treating_doctor_id'], $f['today'], 'treating_doctor_id', false);
            $fields = Arr::only($data, ['modality', 'intent', 'protocol_text', 'protocol_clinic_id', 'protocol_doctor_id', 'treating_clinic_id', 'treating_doctor_id']);
            $content = OncologyIntegrity::content($fields);
            if ($priorRevision && $content === OncologyIntegrity::content((array) $priorRevision)) {
                OncologyIntegrity::reject('ONCOLOGY_NO_CLINICAL_CHANGE', 'لم يتغير البروتوكول أو النمط أو النية أو الطبيبان؛ لم تُنشأ نسخة جديدة.');
            }
            $duplicate = null;
            if (! $old) {
                $candidates = DB::table('oncology_plans as p')->join('oncology_plan_revisions as r', 'r.id', '=', 'p.current_revision_id')->where('p.dossier_id', $dossier)->where('p.facility_id', $f['id'])->select('r.*')->get();
                foreach ($candidates as $candidate) {
                    if ($content === OncologyIntegrity::content((array) $candidate)) {
                        $duplicate = $candidate->plan_id;
                        break;
                    }
                }
                if ($duplicate && ! ($data['confirm_duplicate'] ?? false)) {
                    OncologyIntegrity::reject('ONCOLOGY_DUPLICATE_PLAN', 'توجد خطة مفتوحة بالمحتوى السريري نفسه. راجعها قبل إنشاء نسخة مستقلة مقصودة.', ['existing_plan_id' => (int) $duplicate], 409);
                }
                if (($data['confirm_duplicate'] ?? false) && blank($data['duplicate_reason'] ?? null)) {
                    throw ValidationException::withMessages(['duplicate_reason' => 'إنشاء خطة مطابقة مقصودة يحتاج سببًا صريحًا.']);
                }
            }
            $number = $old ? DB::table('oncology_plan_revisions')->where('plan_id', $id)->max('revision_number') + 1 : 1;
            $plan = $old ?? (object) ['id' => $this->persist($r, $f, 'oncology_plans', null, ['dossier_id' => $dossier, 'facility_id' => $f['id'], 'client_request_id' => $data['request_id'], 'status' => 'active'])];
            $revision = DB::table('oncology_plan_revisions')->insertGetId($fields + ['plan_id' => $plan->id, 'dossier_id' => $dossier, 'facility_id' => $f['id'], 'revision_number' => $number, 'client_request_id' => $data['request_id'], 'entered_by' => $r->user()->id, 'created_at' => now()]);
            if ($duplicate) {
                $this->writes->audit($r, $f, 'oncology_plans', $plan->id, null, ['existing_plan_id' => $duplicate, 'duplicate_reason' => $data['duplicate_reason']], 'duplicate_confirmed');
            }
            $this->writes->audit($r, $f, 'oncology_plan_revisions', $revision, null, $fields + ['plan_id' => $plan->id, 'revision_number' => $number]);
            // Initial allocation is inside the transaction; no competing patient identity.
            $current = DB::table('oncology_plans')->where('id', $plan->id)->first();
            $this->persist($r, $f, 'oncology_plans', $current, ['current_revision_id' => $revision, 'plan_number' => $old?->plan_number ?? 'TP-'.str_pad((string) $plan->id, 8, '0', STR_PAD_LEFT), 'status' => 'active']);

            return (int) $plan->id;
        });
    }

    public function status(Request $r, array $f, int $dossier, int $id, array $data): int
    {
        $targets = $this->planTargets($f, $dossier, $id);

        return $this->writes->once($r, $f, $data, "oncology:status:$dossier:$id", function () use ($r, $f, $dossier, $id, $data, $targets) {
            $this->context->lock($targets);
            $plan = $this->plan($f, $dossier, $id, $data['lock_version']);
            if ($data['status'] !== 'active') {
                throw ValidationException::withMessages(['status' => 'كل الخطط العلاجية تبقى فعالة، ولا تُوقف أو تُكمل أو تُلغى.']);
            }

            return $this->persist($r, $f, 'oncology_plans', $plan, ['status' => 'active']);
        });
    }

    public function schedule(Request $r, array $f, int $dossier, int $planId, array $data): int
    {
        $targets = $this->planTargets($f, $dossier, $planId);

        return $this->writes->once($r, $f, $data, "oncology:schedule:$dossier:$planId", function () use ($r, $f, $dossier, $planId, $data, $targets) {
            $this->context->lock($targets);
            $plan = $this->plan($f, $dossier, $planId, $data['lock_version']);
            $this->active($f, $dossier, $planId);
            $revision = DB::table('oncology_plan_revisions')->where('id', $plan->current_revision_id)->first();
            $assigned = DB::table('oncology_sessions')->where('plan_id', $planId)->pluck('session_number')->map(fn ($n) => (int) $n)->all();
            $cursor = $assigned ? max($assigned) : 0;
            foreach ($data['sessions'] as $i => $session) {
                if (! empty($session['session_number'])) {
                    $number = (int) $session['session_number'];
                } else {
                    do {
                        $number = ++$cursor;
                    } while (in_array($number, $assigned, true));
                }
                if (in_array($number, $assigned, true)) {
                    throw ValidationException::withMessages(["sessions.$i.planned_on" => 'هذا الموعد مكرر ضمن الخطة؛ عدّل التاريخ بدل إضافته مرة أخرى.']);
                }
                $assigned[] = $number;
                $cursor = max($cursor, $number);
                $this->context->check($f, $revision->treating_clinic_id, $revision->treating_doctor_id, $session['planned_on'], "sessions.$i.planned_on", false);
                $this->persist($r, $f, 'oncology_sessions', null, Arr::only($session, ['planned_on', 'note']) + ['session_number' => $number, 'plan_id' => $planId, 'revision_id' => $revision->id, 'dossier_id' => $dossier, 'facility_id' => $f['id'], 'clinic_id' => $revision->treating_clinic_id, 'doctor_id' => $revision->treating_doctor_id, 'client_request_id' => (string) Str::uuid(), 'status' => 'scheduled']);
            }
            $this->persist($r, $f, 'oncology_plans', $plan, []);

            return $planId;
        });
    }

    public function session(Request $r, array $f, int $dossier, int $id, array $data): int
    {
        $target = DB::table('oncology_sessions')->where('id', $id)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->first();
        abort_unless($target, 404);
        $targets = $target->plan_id ? $this->planTargets($f, $dossier, $target->plan_id) : [[], []];

        return $this->writes->once($r, $f, $data, "oncology:session:$dossier:$id", function () use ($r, $f, $dossier, $id, $data, $target, $targets) {
            $this->context->lock([array_merge($targets[0], [$target->doctor_id]), array_merge($targets[1], [$target->clinic_id])]);
            $this->writes->dossier($f, $dossier);
            $s = DB::table('oncology_sessions')->where('id', $id)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->lockForUpdate()->first();
            abort_unless($s, 404);
            DossierWrites::version((array) $s, $data['lock_version']);
            if ($s->status === 'completed') {
                DossierWrites::conflict('هذه الجلسة لها واقعة فعلية؛ صحح الواقعة أو أبطلها ولا تعِد جدولتها.');
            }
            if (! $s->plan_id) {
                if (! empty($data['carry_forward'])) {
                    throw ValidationException::withMessages(['carry_forward' => 'الموعد مستقل ولا يملك نسخة خطة لنقلها.']);
                }
                $fields = Arr::only($data, ['status', 'reason']);
                if ($data['status'] === 'rescheduled') {
                    $this->context->check($f, $s->clinic_id, $s->doctor_id, $data['planned_on'], 'planned_on', false);
                    $fields['planned_on'] = $data['planned_on'];
                }

                return $this->persist($r, $f, 'oncology_sessions', $s, $fields);
            }
            if (empty($data['plan_lock_version'])) {
                throw ValidationException::withMessages(['plan_lock_version' => 'نسخة الخطة مطلوبة للموعد المرتبط بها.']);
            }
            $plan = $this->plan($f, $dossier, $s->plan_id, $data['plan_lock_version']);

            return $this->resolveSession($r, $f, $s, $plan, $data);
        });
    }

    public function sessionDose(Request $r, array $f, int $dossier, int $sessionId, array $data, ?int $doseId = null): int
    {
        $target = DB::table('oncology_sessions')->where('id', $sessionId)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->first();
        abort_unless($target, 404);
        $targets = $target->plan_id ? $this->planTargets($f, $dossier, (int) $target->plan_id) : [[], []];

        return $this->writes->once($r, $f, $data, 'oncology:session-dose:'.$dossier.':'.$sessionId.':'.($doseId ?? 'new'), function () use ($r, $f, $dossier, $sessionId, $data, $doseId, $targets) {
            $this->context->lock([array_merge($targets[0], [$data['nurse_id']]), $targets[1]]);
            $this->writes->dossier($f, $dossier);
            $session = DB::table('oncology_sessions')->where('id', $sessionId)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->lockForUpdate()->first();
            abort_unless($session, 404);
            if (! in_array($session->status, ['scheduled', 'rescheduled'], true)) {
                throw ValidationException::withMessages(['given_on' => 'الجرعة تُسجل لجلسة مجدولة. غيّر حالة الموعد أولًا إن كان ملغى أو منتهيًا.']);
            }
            $old = null;
            if ($doseId) {
                $old = DB::table('oncology_session_doses')->where('id', $doseId)->where('session_id', $session->id)->where('facility_id', $f['id'])->lockForUpdate()->first();
                abort_unless($old, 404);
                DossierWrites::version((array) $old, $data['lock_version']);
            }
            if (! DB::table('staff as s')->join('clinic_staff as cs', 'cs.staff_id', '=', 's.id')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')->where('s.id', $data['nurse_id'])->where('c.id', $session->clinic_id)->where('c.facility_id', $f['id'])->where('c.is_active', true)->whereNull('c.archived_at')->where('s.is_active', true)->whereNull('s.archived_at')->where('cs.starts_on', '<=', $data['given_on'])->where(fn ($q) => $q->whereNull('cs.ends_on')->orWhere('cs.ends_on', '>', $data['given_on']))->exists()) {
                throw ValidationException::withMessages(['nurse_id' => 'اختر ممرضًا مرتبطًا بعيادة هذه الجلسة في تاريخ الجرعة.']);
            }

            return $this->persist($r, $f, 'oncology_session_doses', $old, [
                'session_id' => $session->id, 'dossier_id' => $dossier, 'facility_id' => $f['id'],
                'given_on' => $data['given_on'], 'dose_name' => trim($data['dose_name']), 'complaint' => trim($data['complaint']),
                'recommendations' => trim($data['recommendations']), 'nurse_id' => $data['nurse_id'],
            ] + ($old ? [] : ['client_request_id' => $data['request_id']]));
        });
    }

    public function appointment(Request $r, array $f, int $dossier, array $data): int
    {
        return $this->writes->once($r, $f, $data, "oncology:appointment:$dossier", function () use ($r, $f, $dossier, $data) {
            $this->context->lock([[$data['doctor_id']], [$data['clinic_id']]]);
            $this->writes->dossier($f, $dossier);
            $this->context->check($f, $data['clinic_id'], $data['doctor_id'], $data['planned_on'], 'doctor_id', false);

            return $this->persist($r, $f, 'oncology_sessions', null, Arr::only($data, ['clinic_id', 'doctor_id', 'planned_on', 'note']) + [
                'dossier_id' => $dossier, 'facility_id' => $f['id'], 'plan_id' => null, 'revision_id' => null,
                'session_number' => null, 'status' => 'scheduled', 'client_request_id' => $data['request_id'],
            ]);
        });
    }

    private function resolveSession(Request $r, array $f, object $s, object $plan, array $data): int
    {
        if (($data['carry_forward'] ?? false) && ($data['status'] !== 'rescheduled'
            || $s->revision_id == $plan->current_revision_id || empty($data['planned_on']))) {
            OncologyIntegrity::reject('ONCOLOGY_INVALID_CARRY_FORWARD', 'نقل النسخة متاح فقط لموعد من نسخة سابقة، مع إعادة جدولة وتاريخ صريح.');
        }
        if ($data['status'] === 'rescheduled' && empty($data['planned_on'])) {
            OncologyIntegrity::reject('ONCOLOGY_RESCHEDULE_DATE_REQUIRED', 'حدد تاريخًا صريحًا لإعادة الجدولة.', ['fields' => ['planned_on' => ['تاريخ إعادة الجدولة مطلوب.']]]);
        }
        if (DB::table('dose_sessions')->where('oncology_session_id', $s->id)->whereNull('voided_at')->exists()) {
            DossierWrites::conflict('للموعد إعطاء فعال؛ صحح الواقعة أو أبطلها مع معالجة الموعد.');
        }
        $fields = ['status' => $data['status'], 'reason' => $data['reason']];
        $revision = DB::table('oncology_plan_revisions')->where('id', $plan->current_revision_id)->first();
        if ($data['carry_forward'] ?? false) {
            $this->active($f, $s->dossier_id, $plan->id);
            $fields += ['revision_id' => $revision->id, 'clinic_id' => $revision->treating_clinic_id, 'doctor_id' => $revision->treating_doctor_id];
        }
        if ($data['status'] === 'rescheduled') {
            $this->active($f, $s->dossier_id, $plan->id);
            if (($fields['revision_id'] ?? $s->revision_id) != $revision->id) {
                OncologyIntegrity::reject('ONCOLOGY_OBSOLETE_SESSION_REVISION', 'نسخة علاجية سابقة — تحتاج معالجة قبل الإعطاء. أكد النقل إلى النسخة الحالية صراحة.');
            }
            $fields['planned_on'] = $data['planned_on'];
            $this->context->check($f, $fields['clinic_id'] ?? $s->clinic_id, $fields['doctor_id'] ?? $s->doctor_id, $fields['planned_on'], 'planned_on', false);
        }

        return $this->persist($r, $f, 'oncology_sessions', $s, $fields);
    }

    private function active(array $f, int $dossier, int $id): void
    {
        $effective = $this->queries->plans($f)->where('p.dossier_id', $dossier)->where('p.id', $id)->selectRaw(OncologyQueries::effectiveSql().' AS state')->first();
        if ($effective?->state !== 'active') {
            throw ValidationException::withMessages(['plan_id' => 'تعذّر متابعة هذه الخطة.']);
        }
    }

    private function planTargets(array $f, int $dossier, int $id): array
    {
        $row = DB::table('oncology_plans as p')->join('oncology_plan_revisions as r', 'r.id', '=', 'p.current_revision_id')->where('p.id', $id)->where('p.dossier_id', $dossier)->where('p.facility_id', $f['id'])->first(['r.protocol_doctor_id', 'r.treating_doctor_id', 'r.protocol_clinic_id', 'r.treating_clinic_id']);
        abort_unless($row, 404);

        // Staff then clinics then dossier, shared with directory linking writes.
        // The subsequent locked plan version rejects a changed target revision.
        return [[$row->protocol_doctor_id, $row->treating_doctor_id], [$row->protocol_clinic_id, $row->treating_clinic_id]];
    }

    private function period(array $f, int $id, string $date, ?int $old = null): void
    {
        $period = DB::table('reporting_periods')->where('facility_id', $f['id'])->where('id', $id)->lockForUpdate()->first();
        if (! $period || $period->status !== 'open' || $date < $period->starts_on || $date > $period->ends_on) {
            throw ValidationException::withMessages(['reporting_period_id' => 'اختر فترة مفتوحة في هذا المشفى تغطي تاريخ الواقعة الفعلي.']);
        }
        if ($old && $old !== $id && ! DB::table('reporting_periods')->where('facility_id', $f['id'])->where('id', $old)->where('status', 'open')->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['reporting_period_id' => 'الفترة السابقة مغلقة؛ التصحيح غير متاح.']);
        }
    }

    public function medication(array $item, ?object $old, string $field): array
    {
        if ($old && ! array_key_exists('medication_id', $item)) {
            $item['medication_id'] = $old->medication_id;
        }
        if (! $old || ($old->medication_id ?? null) != ($item['medication_id'] ?? null)) {
            if (! empty($item['medication_id'])) {
                $med = DB::table('medications')->where('id', $item['medication_id'])->where('is_active', true)->first();
                if (! $med) {
                    throw ValidationException::withMessages([$field.'.medication_id' => 'اختر دواء فعالًا من الدليل.']);
                }
                $item['medication_name_snapshot'] = $med->name_ar;
                $item['medication_code_snapshot'] = $med->code;
            } elseif (blank($item['medication_name_snapshot'] ?? null)) {
                throw ValidationException::withMessages([$field.'.medication_name_snapshot' => 'يلزم تعريف الدواء أو اسمه التاريخي الصريح.']);
            }
        } else {
            $item['medication_name_snapshot'] = $old->medication_name_snapshot;
            $item['medication_code_snapshot'] = $old->medication_code_snapshot;
        }
        if (! empty($item['funding_source_id']) && ($old?->funding_source_id ?? null) != $item['funding_source_id'] && ! DB::table('funding_sources')->where('id', $item['funding_source_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([$field.'.funding_source_id' => 'اختر مصدر تمويل فعالًا.']);
        }

        return $item;
    }

    public function administer(Request $r, array $f, int $dossier, int $visit, array $data, ?int $id = null, bool $void = false): int
    {
        $targets = [[], []];
        if (! $id) {
            $target = DB::table('oncology_sessions')->where('id', $data['session_id'])->where('dossier_id', $dossier)->where('facility_id', $f['id'])->first();
            abort_unless($target, 404);
            if (! $target->plan_id) {
                throw ValidationException::withMessages(['session_id' => 'هذا موعد مستقل فقط؛ تسجيل الإعطاء العلاجي يتطلب جلسة خطة معتمدة.']);
            }
            $targets = $this->planTargets($f, $dossier, $target->plan_id);
            $targets[0][] = $target->doctor_id;
            $targets[1][] = $target->clinic_id;
        } elseif ($void) {
            $target = DB::table('dose_sessions as d')->join('oncology_sessions as s', 's.id', '=', 'd.oncology_session_id')->where('d.id', $id)->where('d.visit_id', $visit)->where('d.dossier_id', $dossier)->where('d.facility_id', $f['id'])->first(['s.plan_id', 's.doctor_id', 's.clinic_id']);
            abort_unless($target, 404);
            $targets = $this->planTargets($f, $dossier, $target->plan_id);
            $targets[0][] = $target->doctor_id;
            $targets[1][] = $target->clinic_id;
        }

        return $this->writes->once($r, $f, $data, "oncology:dose:$dossier:$visit:".($id ?? 'new').':'.($void ? 'void' : 'save'), function () use ($r, $f, $dossier, $visit, $data, $id, $void, $targets) {
            $this->context->lock([array_merge($targets[0], array_filter([$data['supervising_staff_id'] ?? null, $data['administered_by'] ?? null])), $targets[1]]);
            $v = app(DossierPathology::class)->visit($f, $dossier, $visit, true);
            $old = $id ? DB::table('dose_sessions')->where('id', $id)->where('visit_id', $visit)->where('facility_id', $f['id'])->whereNotNull('oncology_session_id')->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($old, 404);
                DossierWrites::version((array) $old, $data['lock_version']);
                if ($old->voided_at) {
                    DossierWrites::conflict('الواقعة مبطلة ومحفوظة تاريخيًا.');
                }
            }
            if ($void) {
                abort_unless(in_array('dossiers.treatment.schedule', $f['permissions'], true), 403);
                if (! in_array($data['session_resolution'] ?? null, ['rescheduled', 'missed', 'cancelled', 'referred'], true)
                    || (($data['session_resolution'] ?? null) === 'rescheduled' && empty($data['planned_on']))) {
                    OncologyIntegrity::reject('ONCOLOGY_INVALID_VOID_RESOLUTION', 'إبطال الإعطاء يحتاج اختيار معالجة الموعد وتاريخًا صريحًا عند إعادة الجدولة.');
                }
                $this->period($f, $old->reporting_period_id, $old->administered_on);
                $session = DB::table('oncology_sessions')->where('id', $old->oncology_session_id)->lockForUpdate()->first();
                DossierWrites::version((array) $session, $data['session_lock_version']);
                $plan = $this->plan($f, $dossier, $session->plan_id, $data['plan_lock_version']);
                $saved = $this->persist($r, $f, 'dose_sessions', $old, ['voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $data['reason']]);
                $this->resolveSession($r, $f, $session, $plan, ['status' => $data['session_resolution']] + $data);

                return $saved;
            }
            if ($data['administered_on'] !== $v->visit_date || $data['administered_on'] > $f['today']) {
                OncologyIntegrity::reject('ONCOLOGY_SCHEDULE_DATE_MISMATCH', 'تاريخ الإعطاء والموعد يجب أن يطابقا تاريخ الزيارة الفعلية غير المستقبلي.');
            }
            $this->period($f, $data['reporting_period_id'], $data['administered_on'], $old?->reporting_period_id);
            $session = DB::table('oncology_sessions')->where('id', $old?->oncology_session_id ?? $data['session_id'])->where('dossier_id', $dossier)->where('facility_id', $f['id'])->lockForUpdate()->first();
            abort_unless($session, 404);
            $plan = $this->plan($f, $dossier, $session->plan_id);
            if (! $old) {
                DossierWrites::version((array) $session, $data['session_lock_version']);
                DossierWrites::version((array) $plan, $data['plan_lock_version']);
                DossierWrites::version((array) $v, $data['visit_lock_version']);
                $this->active($f, $dossier, $plan->id);
                if ($session->revision_id != $plan->current_revision_id) {
                    OncologyIntegrity::reject('ONCOLOGY_OBSOLETE_SESSION_REVISION', 'نسخة علاجية سابقة — تحتاج معالجة قبل الإعطاء.');
                }
                if ($session->planned_on !== $v->visit_date) {
                    OncologyIntegrity::reject('ONCOLOGY_SCHEDULE_DATE_MISMATCH', 'أعد جدولة الجلسة صراحة إلى تاريخ الحضور الفعلي قبل تسجيل الإعطاء.');
                }
                $this->context->check($f, $session->clinic_id, $session->doctor_id, $session->planned_on, 'session_id', false);
                if (! in_array($session->status, ['scheduled', 'rescheduled']) || DB::table('dose_sessions')->where('oncology_session_id', $session->id)->whereNull('voided_at')->exists()) {
                    DossierWrites::conflict('الجلسة غير متاحة للإعطاء أو سُجل حضورها مسبقًا.');
                }
                if (DB::table('visit_outcomes as o')->join('visit_results as r', 'r.id', '=', 'o.result_id')->where('o.visit_id', $visit)->whereNull('o.voided_at')->where('r.code', 'DOS-REFER')->exists()) {
                    throw ValidationException::withMessages(['visit_id' => 'الزيارة المحالة لا تسجّل جرعة معطاة؛ راجع النتيجة السريرية أولًا.']);
                }
            }
            $doseRevision = DB::table('oncology_plan_revisions')->where('id', $old?->plan_revision_id ?? $session->revision_id)->first();
            $this->context->check($f, $doseRevision->treating_clinic_id, $data['supervising_staff_id'], $v->visit_date, 'supervising_staff_id', false);
            if (! DB::table('staff as s')->join('clinic_staff as cs', 'cs.staff_id', '=', 's.id')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')->where('s.id', $data['administered_by'])->where('c.facility_id', $f['id'])->where('c.is_active', true)->whereNull('c.archived_at')->where('s.is_active', true)->whereNull('s.archived_at')->where('cs.starts_on', '<=', $v->visit_date)->where(fn ($q) => $q->whereNull('cs.ends_on')->orWhere('cs.ends_on', '>', $v->visit_date))->exists()) {
                throw ValidationException::withMessages(['administered_by' => 'اختر عضو كادر فعالًا له ارتباط في هذا المشفى يغطي تاريخ الإعطاء.']);
            }
            $fields = Arr::only($data, ['reporting_period_id', 'administered_on', 'supervising_staff_id', 'administered_by', 'session_label', 'note']);
            if (! $old) {
                $fields += ['visit_id' => $visit, 'facility_id' => $f['id'], 'dossier_id' => $dossier, 'oncology_session_id' => $session->id, 'oncology_plan_id' => $plan->id, 'plan_revision_id' => $session->revision_id, 'activation_basis' => $plan->activation_basis, 'client_request_id' => $data['request_id']];
            }
            $saved = $this->persist($r, $f, 'dose_sessions', $old, $fields);
            foreach ($data['items'] as $i => $item) {
                $prior = ! empty($item['id']) ? DB::table('dose_session_items')->where('id', $item['id'])->where('dose_session_id', $saved)->whereNull('voided_at')->lockForUpdate()->first() : null;
                if (! empty($item['id'])) {
                    abort_unless($prior, 404);
                    DossierWrites::version((array) $prior, $item['lock_version']);
                }
                if (! empty($item['remove'])) {
                    if (! $prior || blank($item['void_reason'] ?? null)) {
                        throw ValidationException::withMessages(["items.$i.void_reason" => 'إبطال بند محفوظ يحتاج سببًا.']);
                    }
                    $this->persist($r, $f, 'dose_session_items', $prior, ['voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $item['void_reason']]);
                } else {
                    $item = $this->medication($item, $prior, "items.$i");
                    $this->persist($r, $f, 'dose_session_items', $prior, Arr::only($item, ['medication_id', 'medication_name_snapshot', 'medication_code_snapshot', 'funding_source_id', 'dose_text', 'dose_value', 'dose_unit', 'quantity', 'quantity_unit', 'route', 'note']) + ['dose_session_id' => $saved]);
                }
            }
            if (! DB::table('dose_session_items')->where('dose_session_id', $saved)->whereNull('voided_at')->exists()) {
                $modality = $doseRevision->modality;
                if ($modality !== 'radiotherapy' || blank($data['session_label'] ?? null) || blank($data['note'] ?? null)) {
                    throw ValidationException::withMessages(['items' => 'وثّق الأدوية المعطاة فعليًا. الجلسة الشعاعية دون أدوية تحتاج عنوانًا ووصفًا صريحًا لما أُجري؛ الموعد وحده ليس إعطاءً.']);
                }
            }
            if (! $old) {
                $this->persist($r, $f, 'oncology_sessions', $session, ['status' => 'completed']);
            }
            if ($old) {
                $this->writes->audit($r, $f, 'dose_sessions', $saved, null, ['correction_reason' => $data['reason']], 'corrected');
            }

            return $saved;
        });
    }

    public function dispense(Request $r, array $f, int $dossier, int $visit, array $data, ?int $id = null, bool $void = false): int
    {
        return $this->writes->once($r, $f, $data, "oncology:dispense:$dossier:$visit:".($id ?? 'new').':'.($void ? 'void' : 'save'), function () use ($r, $f, $dossier, $visit, $data, $id, $void) {
            $this->context->lock([array_filter([$data['prescribing_staff_id'] ?? null]), []]);
            $v = app(DossierPathology::class)->visit($f, $dossier, $visit, true);
            $old = $id ? DB::table('visit_medications')->where('id', $id)->where('visit_id', $visit)->where('facility_id', $f['id'])->whereNotNull('dose_session_id')->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($old, 404);
                DossierWrites::version((array) $old, $data['lock_version']);
                if ($old->voided_at) {
                    DossierWrites::conflict('الصرف مبطل ومحفوظ تاريخيًا.');
                }
            }
            if ($void) {
                $this->period($f, $old->reporting_period_id, $old->dispensed_on);

                return $this->persist($r, $f, 'visit_medications', $old, ['voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $data['reason']]);
            }
            if ($data['dispensed_on'] !== $v->visit_date || $data['dispensed_on'] > $f['today']) {
                throw ValidationException::withMessages(['dispensed_on' => 'الصرف الفعلي يكون بتاريخ الزيارة غير المستقبلي.']);
            }
            $this->period($f, $data['reporting_period_id'], $data['dispensed_on'], $old?->reporting_period_id);
            $dose = DB::table('dose_sessions')->where('id', $old?->dose_session_id ?? $data['dose_session_id'])->where('visit_id', $visit)->where('facility_id', $f['id'])->whereNull('voided_at')->lockForUpdate()->first();
            abort_unless($dose, 404);
            $session = DB::table('oncology_sessions')->where('id', $dose->oncology_session_id)->first();
            abort_unless($session, 404);
            $this->context->check($f, $session->clinic_id, $data['prescribing_staff_id'], $v->visit_date, 'prescribing_staff_id', false);
            $item = $this->medication($data, $old, 'medication');
            $fields = Arr::only($item, ['dispensed_on', 'reporting_period_id', 'medication_id', 'medication_name_snapshot', 'medication_code_snapshot', 'funding_source_id', 'prescribing_staff_id', 'dose_text', 'quantity', 'quantity_unit', 'note', 'dispensing_purpose']);
            if (! $old) {
                $fields += ['visit_id' => $visit, 'facility_id' => $f['id'], 'dose_session_id' => $dose->id, 'client_request_id' => $data['request_id']];
            }
            $saved = $this->persist($r, $f, 'visit_medications', $old, $fields);
            if ($old) {
                $this->writes->audit($r, $f, 'visit_medications', $saved, null, ['correction_reason' => $data['reason']], 'corrected');
            }

            return $saved;
        });
    }
}
