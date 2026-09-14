<?php

namespace App\Services\BloodBank;

use App\Exceptions\BloodBankException;
use App\Http\Requests\BloodBank\SaveBloodProfile;
use App\Services\Catalog\CatalogQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BloodEventQueries
{
    public function people(int $facility, ?string $search = null): Builder
    {
        $q = DB::table('blood_bank_people as p')->leftJoin('patients as patient', 'patient.id', '=', 'p.patient_id')->where('p.facility_id', $facility)
            ->select('p.*', 'patient.patient_code')->selectRaw("CASE WHEN p.patient_id IS NOT NULL THEN CONCAT_WS(' ', patient.first_name, patient.family_name) ELSE COALESCE(NULLIF(CONCAT_WS(' ', p.first_name, p.family_name), ''), p.legacy_name) END as name");
        $q = DB::query()->fromSub($q, 'person');
        if (trim($search ?? '') !== '') {
            $like = BloodBankQueries::like($search);
            $q->where(fn ($q) => $q->where('code', 'like', $like)->orWhere('patient_code', 'like', $like)->orWhereRaw("REGEXP_REPLACE(name, '[[:space:]]+', ' ') LIKE ?", [$like])->orWhereExists(fn ($a) => $a->selectRaw('1')->from('blood_donors')->whereColumn('person_id', 'person.id')->where('donor_code', 'like', $like))->orWhereExists(fn ($a) => $a->selectRaw('1')->from('blood_recipients')->whereColumn('person_id', 'person.id')->where('recipient_code', 'like', $like)));
        }

        return $q;
    }

    public function person(int $id, int $facility): array
    {
        $row = $this->people($facility)->where('id', $id)->first();
        if (! $row) {
            throw new BloodBankException('BLOOD_BANK_NOT_FOUND', 'الشخص غير موجود في المنشأة المتاحة.', 404);
        }
        $p = (array) $row;
        $fields = [...SaveBloodProfile::PERSON, ...SaveBloodProfile::MANUAL_ADDRESS];
        $person = array_intersect_key($p, array_flip($fields));
        if ($p['patient_id']) {
            $patient = DB::table('patients')->where('id', $p['patient_id'])->first(SaveBloodProfile::PERSON);
            $person = (array) $patient + ['governorate_text' => null, 'city_text' => null];
        }
        $aliases = DB::table('blood_donors')->where('person_id', $id)->where('donor_code', '<>', $p['code'])->pluck('donor_code')->merge(DB::table('blood_recipients')->where('person_id', $id)->pluck('recipient_code'))->all();
        $legacy = DB::table('blood_bank_screenings as s')->whereIn('donor_id', DB::table('blood_donors')->where('person_id', $id)->select('id'))->orWhereIn('recipient_id', DB::table('blood_recipients')->where('person_id', $id)->select('id'))->get(['analyte', 'status', 'screening_test_id', 'result']);

        return ['id' => $id, 'facility_id' => $facility, 'code' => $p['code'], 'name' => $p['name'], 'patient_id' => $p['patient_id'], 'patient_code' => $p['patient_code'], 'person' => $person,
            'blood_group' => $p['blood_group'], 'rh' => $p['rh'], 'is_active' => (bool) $p['is_active'], 'lock_version' => $p['lock_version'], 'aliases' => $aliases,
            'governorate_name' => $person['governorate_text'] ?: DB::table('governorates')->where('id', $person['governorate_id'])->value('name_ar'),
            'city_name' => $person['city_text'] ?: DB::table('cities')->where('id', $person['city_id'])->value('name_ar'),
            'legacy_screenings' => $legacy, 'totals' => $this->totals($this->events($facility, ['person_id' => $id]))];
    }

    public function events(int $facility, array $filters = []): Builder
    {
        $q = DB::table('blood_bank_events as e')->joinSub($this->people($facility), 'person', 'person.id', '=', 'e.person_id')
            ->leftJoin('blood_components as b', 'b.id', '=', 'e.blood_component_id')->leftJoin('clinics as c', 'c.id', '=', 'e.clinic_id')->leftJoin('staff as s', 's.id', '=', 'e.responsible_staff_id')
            ->where('e.facility_id', $facility)->select('e.*', 'person.name', 'person.code as person_code', 'person.patient_code', 'b.name_ar as component_name', 'c.name_ar as clinic_name', 's.full_name as doctor_name');
        foreach (['kind', 'benefit_kind', 'blood_component_id', 'blood_group', 'rh', 'clinic_id', 'person_id'] as $key) {
            if (! empty($filters[$key])) {
                $q->where('e.'.$key, $filters[$key]);
            }
        }
        if (! empty($filters['from'])) {
            $q->where('e.occurred_on', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('e.occurred_on', '<=', $filters['to']);
        }
        if (trim($filters['search'] ?? '') !== '') {
            $like = BloodBankQueries::like($filters['search']);
            $q->where(fn ($q) => $q->whereIn('e.person_id', $this->people($facility, $filters['search'])->select('id'))->orWhereExists(fn ($a) => $a->selectRaw('1')->from('blood_bank_event_codes')->whereColumn('event_id', 'e.id')->where('code', 'like', $like)));
        }
        if (! empty($filters['available_issues'])) {
            $q->where('e.benefit_kind', 'issue')->whereNull('e.voided_at')->whereNotIn('e.id', DB::table('blood_bank_events')->whereNotNull('issue_event_id')->select('issue_event_id'));
        }

        return $q;
    }

    public function totals(Builder $query): array
    {
        $q = (clone $query)->whereNull('e.voided_at');
        $r = $q->select([])->selectRaw("COUNT(DISTINCT CASE WHEN e.kind = 'donation' THEN e.id END) as donations, COUNT(DISTINCT CASE WHEN e.kind = 'benefit' THEN COALESCE(e.issue_event_id, e.id) END) as benefits, COUNT(DISTINCT e.person_id) as unique_people")->first();

        return ['donations' => (int) $r->donations, 'benefits' => (int) $r->benefits, 'unique_people' => (int) $r->unique_people];
    }

    public function ordered(Builder $q, array $filters): Builder
    {
        return $q->orderBy(($filters['sort'] ?? '') === 'name' ? 'person.name' : 'e.'.($filters['sort'] ?? 'occurred_on'), $filters['direction'] ?? 'desc')->orderByDesc('e.id');
    }

    public function listing(int $facility, array $filters): array
    {
        return DB::transaction(function () use ($facility, $filters) {
            $q = $this->events($facility, $filters);
            $totals = $this->totals($q);
            $page = $this->ordered($q, $filters)->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

            return ['data' => $page->items(), 'meta' => CatalogQueries::meta($page), 'totals' => $totals];
        });
    }

    public function event(int $id, int $facility): array
    {
        $r = $this->events($facility)->where('e.id', $id)->first();
        if (! $r) {
            throw new BloodBankException('BLOOD_BANK_NOT_FOUND', 'الواقعة غير موجودة في المنشأة المتاحة.', 404);
        }
        $row = (array) $r;
        $row['screenings'] = DB::table('blood_bank_event_screenings')->where('event_id', $id)->orderBy('analyte')->get(['analyte', 'status', 'screening_test_id', 'result']);
        $row['aliases'] = DB::table('blood_bank_event_codes')->where('event_id', $id)->orderBy('id')->pluck('code');
        $row['linked_transfusion_id'] = DB::table('blood_bank_events')->where('issue_event_id', $id)->value('id');
        $row['legacy_screenings'] = $r->blood_donation_id ? DB::table('blood_donation_screenings as s')->join('screening_tests as t', 't.id', '=', 's.screening_test_id')->where('blood_donation_id', $r->blood_donation_id)->get(['t.name_ar', 's.tested_on'])->all() : [];

        return $row;
    }
}
