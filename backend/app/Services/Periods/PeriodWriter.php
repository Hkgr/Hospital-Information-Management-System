<?php

namespace App\Services\Periods;

use App\Exceptions\ClinicException;
use App\Models\User;
use App\Services\Auth\UserAccessContext;
use App\Services\Clinics\ClinicAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PeriodWriter
{
    public const TABLES = [
        'visits' => 'visit_date',
        'visit_diagnoses' => 'diagnosed_on',
        'visit_services' => 'performed_on',
        'visit_procedures' => 'performed_on',
        'visit_outcomes' => 'outcome_on',
    ];

    public function facility(User $user, int $id, string $action = 'view'): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('periods.view', $entry['permissions'], true)
                && in_array('periods.'.$action, $entry['permissions'], true)) {
                return $entry['facility'] + ['permissions' => $entry['permissions'], 'today' => now($entry['facility']['timezone'])->toDateString()];
            }
        }
        throw new ClinicException('PERIOD_ACCESS_DENIED', 'ليس لديك صلاحية لهذه العملية في المنشأة المحددة.', 403);
    }

    public function listing(array $f): array
    {
        $q = DB::table('reporting_periods as p')->where('p.facility_id', $f['id'])->select('p.*');
        foreach (array_keys(self::TABLES) as $table) {
            $q->selectRaw('(SELECT COUNT(*) FROM `'.$table.'` t WHERE t.reporting_period_id = p.id) as `'.$table.'`');
        }

        return $q->orderByDesc('p.starts_on')->orderByDesc('p.id')->get()->all();
    }

    public function unassigned(array $f): array
    {
        $rows = [];
        foreach (self::TABLES as $table => $date) {
            $groups = DB::table($table)->where('facility_id', $f['id'])->whereNotNull($date)->whereNull('reporting_period_id')
                ->selectRaw("DATE_FORMAT(`$date`, '%Y-%m') as month")->selectRaw('COUNT(*) as count')->groupBy('month')->orderBy('month')->get();
            foreach ($groups as $group) {
                $rows[] = ['table' => $table, 'month' => $group->month, 'count' => (int) $group->count];
            }
        }

        return $rows;
    }

    public function create(Request $r, array $f, array $data): array
    {
        $starts = Carbon::create((int) $data['year'], (int) $data['month'], 1)->toDateString();
        $ends = Carbon::create((int) $data['year'], (int) $data['month'], 1)->endOfMonth()->toDateString();

        return DB::transaction(function () use ($r, $f, $starts, $ends) {
            if (DB::table('reporting_periods')->where('facility_id', $f['id'])->where('starts_on', $starts)->exists()) {
                throw new ClinicException('PERIOD_EXISTS', 'هذه الفترة موجودة مسبقاً.', 409);
            }
            $fields = ['facility_id' => $f['id'], 'starts_on' => $starts, 'ends_on' => $ends, 'status' => 'open', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()];
            $id = DB::table('reporting_periods')->insertGetId($fields);
            app(ClinicAudit::class)->record($r, $f['id'], $id, 'created', null, $fields, 'reporting_period');

            return $this->row($f, $id);
        });
    }

    public function submit(Request $r, array $f, int $id, array $data): array
    {
        return $this->transition($r, $f, $id, $data, 'submitted', ['open'], function (object $row) use ($r) {
            return ['status' => 'submitted', 'submitted_by' => $r->user()->id, 'submitted_at' => now()];
        });
    }

    public function lock(Request $r, array $f, int $id, array $data): array
    {
        return $this->transition($r, $f, $id, $data, 'locked', ['submitted'], function (object $row) use ($r) {
            return ['status' => 'locked', 'locked_by' => $r->user()->id, 'locked_at' => now()];
        });
    }

    public function reopen(Request $r, array $f, int $id, array $data): array
    {
        return $this->transition($r, $f, $id, $data, 'reopened', ['submitted', 'locked'], function (object $row) use ($data) {
            return ['status' => 'open', 'submitted_by' => null, 'submitted_at' => null, 'locked_by' => null, 'locked_at' => null, 'reason' => $data['reason']];
        });
    }

    private function transition(Request $r, array $f, int $id, array $data, string $event, array $from, callable $fields): array
    {
        return DB::transaction(function () use ($r, $f, $id, $data, $event, $from, $fields) {
            $row = DB::table('reporting_periods')->where('id', $id)->where('facility_id', $f['id'])->lockForUpdate()->first();
            abort_unless($row, 404);
            if ((int) $row->lock_version !== (int) $data['lock_version']) {
                throw new ClinicException('PERIOD_VERSION_CONFLICT', 'عدّل مستخدم آخر هذه الفترة. اجلب أحدث نسخة وراجع مسودتك قبل الحفظ.', 409);
            }
            if (! in_array($row->status, $from, true)) {
                throw new ClinicException('PERIOD_INVALID_TRANSITION', 'لا يمكن تنفيذ هذا الإجراء على الفترة بحالتها الحالية.', 409);
            }
            $update = $fields($row) + ['lock_version' => $row->lock_version + 1, 'updated_at' => now()];
            $stored = $update;
            unset($stored['reason']);
            DB::table('reporting_periods')->where('id', $id)->update($stored);
            app(ClinicAudit::class)->record($r, $f['id'], $id, $event, (array) $row, $update, 'reporting_period');

            return $this->row($f, $id);
        });
    }

    private function row(array $f, int $id): array
    {
        $row = DB::table('reporting_periods')->where('id', $id)->where('facility_id', $f['id'])->first();
        abort_unless($row, 404);

        return (array) $row;
    }
}
