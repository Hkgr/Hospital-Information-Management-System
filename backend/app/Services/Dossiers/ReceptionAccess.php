<?php

namespace App\Services\Dossiers;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReceptionAccess
{
    public function facility(User $user, int $id, string $action = 'view'): array
    {
        $permission = ['view' => 'patients.basic.view', 'register' => 'patient_cards.register', 'correct' => 'patients.own.correct', 'corrections.request' => 'patients.corrections.request'][$action] ?? 'reception.'.$action;
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id && in_array('patients.basic.view', $entry['permissions'], true) && in_array($permission, $entry['permissions'], true)) {
                return $entry['facility'] + ['today' => now($entry['facility']['timezone'])->toDateString(), 'permissions' => $entry['permissions']];
            }
        }
        abort(403, 'لا تتوفر صلاحية بيانات البطاقة الأساسية أو إضافتها في هذا المشفى. راجع مسؤول الصلاحيات.');
    }

    /** Bounded card lookup; local access never becomes a global directory grant. */
    public function scopePatients(Builder $query, User $user, array $facility): Builder
    {
        if (app(GlobalAccess::class)->allows($user, 'patients.basic.search')) {
            return $query;
        }

        return $query->where(function ($q) use ($user, $facility) {
            $q->whereExists(fn ($d) => $d->selectRaw('1')->from('patient_dossiers as scope_d')->whereColumn('scope_d.patient_id', 'p.id')->where('scope_d.facility_id', $facility['id']))
                ->orWhereExists(fn ($v) => $v->selectRaw('1')->from('visits as scope_v')->whereColumn('scope_v.patient_id', 'p.id')->where('scope_v.facility_id', $facility['id']))
                ->orWhere(fn ($owned) => $owned->where('p.created_by', $user->id)
                    ->whereNotExists(fn ($d) => $d->selectRaw('1')->from('patient_dossiers as any_d')->whereColumn('any_d.patient_id', 'p.id'))
                    ->whereNotExists(fn ($v) => $v->selectRaw('1')->from('visits as any_v')->whereColumn('any_v.patient_id', 'p.id')));
        });
    }

    public function assertPatient(User $user, array $facility, int $patient): void
    {
        abort_unless($this->scopePatients(DB::table('patients as p')->where('p.id', $patient)->where('p.status', 'active'), $user, $facility)->exists(), 403,
            'هذه الهوية خارج نطاق البطاقة المسموح في المشفى. راجع موظفًا مخولًا بمطابقة الهوية المشتركة؛ لا تنشئ نسخة بديلة.');
    }
}
