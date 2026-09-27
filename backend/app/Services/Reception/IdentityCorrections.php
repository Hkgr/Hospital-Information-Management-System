<?php

namespace App\Services\Reception;

use App\Services\Clinics\ClinicAudit;
use App\Services\Dossiers\DossierWrites;
use App\Services\Dossiers\PatientCardCodes;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class IdentityCorrections
{
    public const FIELDS = ['first_name', 'family_name', 'father_name', 'mother_name', 'birth_date', 'birth_date_accuracy', 'gender', 'phone', 'alt_phone', 'address_line'];

    public function rows(array $f, int $id): array
    {
        $d = DB::table('patient_dossiers')->where('id', $id)->where('facility_id', $f['id'])->lockForUpdate()->first();
        abort_unless($d, 404);
        $p = DB::table('patients')->where('id', $d->patient_id)->lockForUpdate()->first();
        abort_unless($p, 404);

        return [$p, $d];
    }

    public function eligibility(Request $r, object $p, object $d): array
    {
        $window = DB::table('reception_identity_windows')->where('dossier_id', $d->id)->first();
        $reasons = app(IdentityImpact::class)->reasons($p, $d);
        if (! $window || (int) $window->entered_by !== (int) $r->user()->id || (int) $p->created_by !== (int) $r->user()->id) {
            $reasons[] = 'التصحيح المباشر متاح لمن سجّل الهوية من الاستقبال فقط.';
        }
        if ($window && ((int) $window->patient_version !== (int) $p->lock_version)) {
            $reasons[] = 'تغيّرت الهوية خارج مسودة الاستقبال؛ قدّم طلب مراجعة.';
        }
        $seconds = $window ? max(0, (int) ceil(now()->diffInSeconds(CarbonImmutable::parse($window->expires_at), false))) : 0;
        // The interval is [saved_at, saved_at + 15 minutes); at the deadline review is required.
        if (! $window || now()->greaterThanOrEqualTo(CarbonImmutable::parse($window->expires_at))) {
            $reasons[] = 'انتهت مهلة التصحيح المباشر؛ يمكنك طلب مراجعة.';
        }

        return ['can_correct' => $reasons === [], 'remaining_seconds' => $seconds, 'server_time' => now()->toIso8601String(),
            'expires_at' => $window ? CarbonImmutable::parse($window->expires_at)->toIso8601String() : null,
            'editable_fields' => $window ? json_decode($window->fields, true) : [], 'reasons' => $reasons];
    }

    public function summary(Request $r, array $f, int $id): array
    {
        return DB::transaction(function () use ($r, $f, $id) {
            [$p, $d] = $this->rows($f, $id);

            return ['id' => $id, 'code' => $p->patient_code, 'patient_version' => $p->lock_version, 'dossier_version' => $d->lock_version,
                'values' => Arr::only((array) $p, self::FIELDS), 'correction' => $this->eligibility($r, $p, $d),
                'requests' => DB::table('patient_identity_corrections')->where('dossier_id', $id)->where('requested_by', $r->user()->id)
                    ->orderByDesc('id')->limit(20)->get(['id', 'status', 'reason', 'decision_reason', 'created_at'])];
        });
    }

    private function scopeFingerprint(int $patient): string
    {
        return hash('sha256', DB::table('patient_dossiers')->where('patient_id', $patient)->orderBy('id')->lockForUpdate()->get(['id', 'facility_id', 'lock_version', 'status'])->toJson());
    }

    public function input(Request $r): array
    {
        return $r->validate(['request_id' => 'required|uuid', 'patient_version' => 'required|integer|min:1', 'dossier_version' => 'required|integer|min:1',
            'reason' => 'required|string|max:255', 'changes' => ['required', 'array:'.implode(',', self::FIELDS), 'min:1']]);
    }

    private function proposed(array $changes, object $p, array $f): array
    {
        $values = array_replace(Arr::only((array) $p, self::FIELDS), $changes);
        Validator::make($values, [
            'first_name' => 'required|string|max:80', 'family_name' => 'required|string|max:80',
            'father_name' => 'nullable|string|max:80', 'mother_name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:30', 'alt_phone' => 'nullable|string|max:30', 'address_line' => 'nullable|string|max:255',
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:'.$f['today'], Rule::requiredIf(($values['birth_date_accuracy'] ?? '') !== 'unknown')],
            'birth_date_accuracy' => 'required|in:unknown,exact,year_only,estimated', 'gender' => 'required|in:unknown,male,female',
        ])->validate();

        return Arr::only($values, array_keys($changes));
    }

    public function submit(Request $r, array $f, int $id, bool $direct): int
    {
        $input = $this->input($r);

        return app(DossierWrites::class)->once($r, $f, $input, 'identity:'.($direct ? 'correct:' : 'request:').$id, function () use ($r, $f, $id, $direct, $input) {
            app(ReviewAccess::class)->facility($r, $direct ? 'reception.correct' : 'reception.corrections.request');
            app(PatientCardCodes::class)->reserve();
            [$p, $d] = $this->rows($f, $id);
            $this->versions($p, $d, $input);
            abort_unless($p->status === 'active' && in_array($d->status, ['draft', 'active'], true), 409);
            $changes = $this->proposed($input['changes'], $p, $f);
            if ($direct) {
                $eligibility = $this->eligibility($r, $p, $d);
                if (! $eligibility['can_correct'] || array_diff(array_keys($changes), $eligibility['editable_fields'])) {
                    ReviewAccess::conflict('التصحيح المباشر غير متاح. احتفظ بالمسودة وقدّم طلب مراجعة.', 'IDENTITY_REVIEW_REQUIRED');
                }
                $this->apply($r, $f, $p, $changes, $input['reason']);
                DB::table('reception_identity_windows')->where('dossier_id', $id)->update(['patient_version' => $p->lock_version + 1, 'updated_at' => now()]);

                return $id;
            }
            $request = DB::table('patient_identity_corrections')->insertGetId(['facility_id' => $f['id'], 'dossier_id' => $id, 'patient_id' => $p->id,
                'requested_by' => $r->user()->id, 'baseline' => json_encode(Arr::only((array) $p, array_keys($changes))), 'proposed' => json_encode($changes),
                'patient_version' => $p->lock_version, 'dossier_version' => $d->lock_version, 'scope_fingerprint' => $this->scopeFingerprint($p->id), 'reason' => $input['reason'], 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, $f, 'patient_identity_correction', $request, 'created', null, ['dossier_id' => $id, 'fields' => array_keys($changes), 'reason' => $input['reason']]);

            return $request;
        });
    }

    public function versions(object $p, object $d, array $input): void
    {
        if ((int) $p->lock_version !== (int) $input['patient_version'] || (int) $d->lock_version !== (int) $input['dossier_version']) {
            ReviewAccess::conflict('تغيّرت الهوية أو سياقها. اجلب القيم الحالية وراجعها قبل إرسال طلب جديد.');
        }
    }

    public function apply(Request $r, array $f, object $p, array $changes, string $reason): void
    {
        $values = array_replace((array) $p, $changes);
        DB::table('patients')->where('id', $p->id)->update($changes + ['search_name' => trim(preg_replace('/\s+/u', ' ', $values['first_name'].' '.$values['family_name'])), 'lock_version' => $p->lock_version + 1, 'updated_at' => now()]);
        $this->audit($r, $f, 'patient', $p->id, 'corrected', Arr::only((array) $p, array_keys($changes)), $changes + ['reason' => $reason]);
    }

    public function detail(array $f, int $id): array
    {
        $q = DB::table('patient_identity_corrections')->where('facility_id', $f['id'])->where('id', $id)->first();
        abort_unless($q, 404);
        $p = DB::table('patients')->where('id', $q->patient_id)->first();

        return (array) $q + ['current' => Arr::only((array) $p, array_keys(json_decode($q->proposed, true))),
            'shared_identity' => DB::table('patient_dossiers')->where('patient_id', $p->id)->count() > 1,
            'requester' => DB::table('users')->where('id', $q->requested_by)->value('name')];
    }

    public function decide(Request $r, array $f, int $id): int
    {
        $input = $r->validate(['request_id' => 'required|uuid', 'lock_version' => 'required|integer|min:1', 'decision' => 'required|in:approved,rejected', 'reason' => 'required|string|max:255']);
        if ($input['decision'] === 'approved') {
            app(ReviewAccess::class)->global($r, 'patients.identity.review');
        }

        return app(DossierWrites::class)->once($r, $f, $input, 'identity:decision:'.$id, function () use ($r, $f, $id, $input) {
            app(ReviewAccess::class)->facility($r, 'identity_corrections.review');
            if ($input['decision'] === 'approved') {
                app(ReviewAccess::class)->global($r, 'patients.identity.review');
            }
            app(PatientCardCodes::class)->reserve();
            $q = DB::table('patient_identity_corrections')->where('facility_id', $f['id'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($q, 404);
            if ($q->status !== 'pending' || (int) $q->lock_version !== $input['lock_version']) {
                ReviewAccess::conflict('سبق اتخاذ قرار أو تغيّرت نسخة الطلب.');
            }
            if ($input['decision'] === 'approved') {
                [$p, $d] = $this->rows($f, $q->dossier_id);
                $this->versions($p, $d, (array) $q);
                if ($q->scope_fingerprint !== $this->scopeFingerprint($p->id)) {
                    ReviewAccess::conflict('تغيّر نطاق الهوية المشتركة؛ يلزم طلب مراجعة جديد.');
                }
                abort_unless($p->status === 'active', 409);
                $changes = $this->proposed(json_decode($q->proposed, true), $p, $f);
                $this->apply($r, $f, $p, $changes, $input['reason']);
            }
            DB::table('patient_identity_corrections')->where('id', $id)->update(['status' => $input['decision'], 'reviewed_by' => $r->user()->id, 'decision_reason' => $input['reason'], 'reviewed_at' => now(), 'updated_at' => now(), 'lock_version' => $q->lock_version + 1]);
            $this->audit($r, $f, 'patient_identity_correction', $id, $input['decision'], ['status' => 'pending'], ['status' => $input['decision'], 'reason' => $input['reason']]);

            return $id;
        });
    }

    public function audit(Request $r, array $f, string $type, int $id, string $event, ?array $old, array $new): void
    {
        app(ClinicAudit::class)->record($r, $f['id'], $id, $event, $old, $new, $type);
    }
}
