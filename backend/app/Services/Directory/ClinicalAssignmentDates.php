<?php

namespace App\Services\Directory;

use App\Services\Clinics\ClinicAccess;
use App\Services\Clinics\ClinicAudit;
use App\Services\Doctors\DoctorAccess;
use App\Services\Dossiers\DossierWrites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClinicalAssignmentDates
{
    public const BASELINE = '2022-01-01';

    /** Half-open intervals; cancelled zero-length intervals reserve no time. */
    public function conflicts(array $rows): bool
    {
        usort($rows, fn ($a, $b) => [$a['starts_on'], $a['id']] <=> [$b['starts_on'], $b['id']]);
        $previous = null;
        $starts = [];
        foreach ($rows as $row) {
            if (! $row['starts_on'] || ($row['ends_on'] !== null && $row['ends_on'] < $row['starts_on'])) {
                return true;
            }
            if (isset($starts[$row['starts_on']])) {
                return true;
            }
            $starts[$row['starts_on']] = true;
            if ($row['ends_on'] === $row['starts_on']) {
                continue;
            }
            if ($previous && ($previous['ends_on'] === null || $row['starts_on'] < $previous['ends_on'])) {
                return true;
            }
            $previous = $row;
        }

        return false;
    }

    public function update(Request $request, bool $doctor, int $parent, int $id, array $data): array
    {
        $access = $doctor
            ? app(DoctorAccess::class)->facility($request->user(), $data['facility_id'], 'link')
            : app(ClinicAccess::class)->authorize($request->user(), $data['facility_id'], 'edit');
        $row = DB::table('clinic_staff as cs')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')
            ->where('cs.id', $id)->where('c.facility_id', $access['id'])->where($doctor ? 'cs.staff_id' : 'cs.clinic_id', $parent)->first(['cs.*']);
        abort_unless($row, 404);

        return DB::transaction(function () use ($request, $data, $row, $access, $doctor) {
            $links = app(ClinicStaffLinks::class);
            $links->lockStaff([$row->staff_id]);
            $links->lockClinics([$row->clinic_id]);
            // Refresh authorization after waiting for the mutation locks.
            $actor = $request->user()->fresh();
            $request->setUserResolver(fn () => $actor);
            if ($doctor) {
                app(DoctorAccess::class)->facility($actor, $data['facility_id'], 'link');
            } else {
                app(ClinicAccess::class)->authorize($actor, $data['facility_id'], 'edit');
            }
            $staff = DB::table('staff')->find($row->staff_id);
            $clinic = DB::table('clinics')->find($row->clinic_id);
            if ((int) $staff->lock_version !== (int) $data['staff_lock_version'] || (int) $clinic->lock_version !== (int) $data['clinic_lock_version']) {
                DossierWrites::conflict('تغيّر الارتباط أو الطبيب أو العيادة. اجلب أحدث سجل وراجع التاريخين.');
            }
            $periods = DB::table('clinic_staff')->where('staff_id', $row->staff_id)->where('clinic_id', $row->clinic_id)->orderBy('id')->lockForUpdate()->get()->map(fn ($p) => (array) $p)->all();
            $old = collect($periods)->firstWhere('id', $row->id);
            abort_unless($old, 404);
            $new = array_replace($old, ['starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on']]);
            if ($new['ends_on'] !== null && $new['ends_on'] <= $new['starts_on'] && ($new['starts_on'] !== $old['starts_on'] || $new['ends_on'] !== $old['ends_on'])) {
                throw ValidationException::withMessages(['ends_on' => 'النهاية غير مشمولة ويجب أن تأتي بعد البداية.']);
            }
            if (($clinic->archived_at || $staff->archived_at) && $old['ends_on'] !== null && $new['ends_on'] !== $old['ends_on']) {
                throw ValidationException::withMessages(['ends_on' => 'لا تعِد فتح أو تمديد ارتباط أُغلق بالأرشفة. يمكن تصحيح البداية مع إبقاء النهاية.']);
            }
            $proposed = array_map(fn ($p) => $p['id'] === $old['id'] ? $new : $p, $periods);
            if ($this->conflicts($proposed)) {
                throw ValidationException::withMessages(['starts_on' => 'التواريخ تتداخل مع فترة أخرى للطرفين أو تحتوي فترة غير صالحة. راجع التاريخ كاملًا.']);
            }
            DB::table('clinic_staff')->where('id', $row->id)->update(['starts_on' => $new['starts_on'], 'ends_on' => $new['ends_on'], 'updated_at' => now()]);
            foreach (['staff' => $row->staff_id, 'clinics' => $row->clinic_id] as $table => $key) {
                DB::table($table)->where('id', $key)->increment('lock_version');
            }
            $new['reason'] = $data['reason'];
            app(ClinicAudit::class)->record($request, $access['id'], $row->clinic_id, 'doctors_changed', $old, $new, 'clinic');
            app(ClinicAudit::class)->record($request, $access['id'], $row->staff_id, 'clinics_changed', $old, $new, 'doctor');

            return $new + ['facility_id' => $access['id'], 'clinic_lock_version' => $clinic->lock_version + 1, 'staff_lock_version' => $staff->lock_version + 1];
        }, 3);
    }

    public function backdate(?int $facility, bool $apply, string $reference): array
    {
        $counts = ['examined' => 0, 'eligible' => 0, 'changed' => 0, 'unchanged' => 0, 'conflicting' => 0];
        $issues = [];
        // Every clinical staff type uses this one relationship table, including
        // nurse/administrator eligibility in treatment. Never touch employment,
        // work days, authorization assignments, sessions or reporting periods.
        $pairs = DB::table('clinic_staff as cs')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')->when($facility, fn ($q) => $q->where('c.facility_id', $facility))
            ->orderBy('cs.staff_id')->orderBy('cs.clinic_id')->distinct()->get(['cs.staff_id', 'cs.clinic_id', 'c.facility_id']);
        foreach ($pairs as $pair) {
            $result = DB::transaction(function () use ($pair, $apply, $reference) {
                app(ClinicStaffLinks::class)->lockStaff([$pair->staff_id]);
                app(ClinicStaffLinks::class)->lockClinics([$pair->clinic_id]);
                $rows = DB::table('clinic_staff')->where('clinic_id', $pair->clinic_id)->where('staff_id', $pair->staff_id)->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
                $eligible = array_filter($rows, fn ($r) => $r['starts_on'] > self::BASELINE && $r['starts_on'] !== $r['ends_on']);
                $ids = array_column($eligible, 'id');
                $proposed = array_map(fn ($r) => in_array($r['id'], $ids, true) ? array_replace($r, ['starts_on' => self::BASELINE]) : $r, $rows);
                $conflict = $this->conflicts($rows) || $this->conflicts($proposed);
                if ($apply && ! $conflict && $eligible) {
                    foreach ($eligible as $old) {
                        $new = array_replace($old, ['starts_on' => self::BASELINE]);
                        DB::table('clinic_staff')->where('id', $old['id'])->update(['starts_on' => self::BASELINE, 'updated_at' => now()]);
                        foreach (['clinic' => ['doctors_changed', $pair->clinic_id], 'doctor' => ['clinics_changed', $pair->staff_id]] as $type => [$event, $id]) {
                            DB::table('audit_logs')->insert(['facility_id' => $pair->facility_id, 'actor_id' => null, 'entity_type' => $type, 'entity_id' => $id, 'event' => $event,
                                'old_values' => json_encode($old, JSON_THROW_ON_ERROR), 'new_values' => json_encode($new + ['source' => 'clinical_assignments_backdate', 'execution_reference' => $reference], JSON_THROW_ON_ERROR),
                                'reason' => $reference, 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
                        }
                    }
                    DB::table('staff')->where('id', $pair->staff_id)->increment('lock_version');
                    DB::table('clinics')->where('id', $pair->clinic_id)->increment('lock_version');
                }

                return [count($rows), count($eligible), $conflict];
            }, 3);
            [$examined, $eligible, $conflict] = $result;
            $counts['examined'] += $examined;
            $counts['eligible'] += $eligible;
            $counts['unchanged'] += $conflict ? 0 : ($apply ? $examined - $eligible : $examined);
            $counts['changed'] += $apply && ! $conflict ? $eligible : 0;
            if ($conflict) {
                $counts['conflicting'] += $examined;
                $issues[] = ['clinic_id' => $pair->clinic_id, 'staff_id' => $pair->staff_id];
            }
        }

        return ['counts' => $counts, 'conflicts' => $issues];
    }
}
