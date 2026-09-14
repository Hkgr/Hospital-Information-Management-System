<?php

namespace App\Services\BloodBank;

use App\Exceptions\BloodBankException;
use App\Http\Requests\BloodBank\SaveBloodProfile;
use App\Services\Catalog\CatalogQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BloodBankQueries
{
    public static function table(string $kind): string
    {
        return $kind === 'donor' ? 'blood_donors' : 'blood_recipients';
    }

    public static function codeColumn(string $kind): string
    {
        return $kind === 'donor' ? 'donor_code' : 'recipient_code';
    }

    public static function like(?string $text): string
    {
        return '%'.addcslashes(trim(preg_replace('/\s+/u', ' ', $text ?? '')), '%_\\').'%';
    }

    private function listingQuery(string $kind, int $facility): Builder
    {
        $name = $kind === 'donor' ? 'b.full_name' : "CONCAT_WS(' ', b.first_name, b.family_name)";

        return DB::table(self::table($kind).' as b')->leftJoin('patients as p', 'p.id', '=', 'b.patient_id')
            ->leftJoin('clinics as c', 'c.id', '=', 'b.clinic_id')->leftJoin('staff as s', 's.id', '=', 'b.responsible_staff_id')
            ->leftJoin('blood_components as bc', 'bc.id', '=', 'b.blood_component_id')->where('b.facility_id', $facility)
            ->select('b.id', 'b.'.self::codeColumn($kind).' as code', 'b.blood_group', 'b.rh', 'b.updated_at', 'c.name_ar as clinic_name', 's.full_name as doctor_name', 'bc.name_ar as component_name')
            ->selectRaw('? as kind', [$kind])->selectRaw("CASE WHEN b.patient_id IS NOT NULL THEN CONCAT_WS(' ', p.first_name, p.family_name) ELSE $name END as name");
    }

    public function listing(array $facility, array $filters): array
    {
        $union = $this->listingQuery('donor', $facility['id'])->unionAll($this->listingQuery('recipient', $facility['id']));
        $q = DB::query()->fromSub($union, 'files');
        if (! empty($filters['kind'])) {
            $q->where('kind', $filters['kind']);
        }
        if (($filters['search'] ?? '') !== '') {
            $like = self::like($filters['search']);
            $q->where(fn ($q) => $q->where('code', 'like', $like)->orWhereRaw("REGEXP_REPLACE(name, '[[:space:]]+', ' ') LIKE ?", [$like]));
        }
        $page = $q->orderBy($filters['sort'] ?? 'code', $filters['direction'] ?? 'asc')->orderBy('kind')->orderBy('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $page->items(), 'meta' => CatalogQueries::meta($page)];
    }

    public function find(string $kind, int $id, int $facility, bool $lock = false): array
    {
        $q = DB::table(self::table($kind))->where('id', $id)->where('facility_id', $facility);
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        if (! $row) {
            throw new BloodBankException('BLOOD_BANK_NOT_FOUND', 'الملف غير موجود في المنشأة المتاحة.', 404);
        }

        return (array) $row;
    }

    public function profile(string $kind, int $id, int $facility): array
    {
        $row = $this->find($kind, $id, $facility);
        $person = array_intersect_key($row, array_flip([...SaveBloodProfile::PERSON, ...SaveBloodProfile::MANUAL_ADDRESS]));
        $patient = null;
        if ($row['patient_id']) {
            $patient = DB::table('patients')->where('id', $row['patient_id'])->first(['id', 'patient_code', ...SaveBloodProfile::PERSON]);
            $person = array_intersect_key((array) $patient, array_flip(SaveBloodProfile::PERSON)) + array_fill_keys(SaveBloodProfile::MANUAL_ADDRESS, null);
        }
        $name = $row['patient_id'] || $kind === 'recipient' ? ($person['first_name'] ?? '').' '.($person['family_name'] ?? '') : $row['full_name'];
        $screens = DB::table('blood_bank_screenings')->where($kind.'_id', $id)->orderBy('analyte')->get(['analyte', 'screening_test_id', 'status', 'result']);
        $screenings = $screens->map(fn ($s) => (array) $s)->all();

        return ['id' => $id, 'kind' => $kind, 'facility_id' => $facility, 'code' => $row[self::codeColumn($kind)], 'name' => $name,
            'person_mode' => $row['patient_id'] ? 'patient' : 'direct', 'patient_id' => $row['patient_id'], 'patient_code' => $patient?->patient_code,
            'person' => $person, 'beneficiary_entity' => $row['beneficiary_entity'] ?? null, 'blood_group' => $row['blood_group'], 'rh' => $row['rh'],
            'clinic_id' => $row['clinic_id'], 'responsible_staff_id' => $row['responsible_staff_id'], 'blood_component_id' => $row['blood_component_id'],
            'clinic_name' => DB::table('clinics')->where('id', $row['clinic_id'])->value('name_ar'),
            'doctor_name' => DB::table('staff')->where('id', $row['responsible_staff_id'])->value('full_name'),
            'governorate_name' => $person['governorate_text'] ?: DB::table('governorates')->where('id', $person['governorate_id'])->value('name_ar'),
            'city_name' => $person['city_text'] ?: DB::table('cities')->where('id', $person['city_id'])->value('name_ar'),
            'component_name' => DB::table('blood_components')->where('id', $row['blood_component_id'])->value('name_ar'),
            'screenings' => $screenings, 'lock_version' => $row['lock_version'], 'updated_at' => $row['updated_at']];
    }

    public function donation(int $donor, int $id, int $facility, bool $lock = false): array
    {
        $q = DB::table('blood_donations')->where('facility_id', $facility)->where('donor_id', $donor)->where('id', $id);
        $row = ($lock ? $q->lockForUpdate() : $q)->first(['id', 'donor_id', 'donation_code', 'donated_on', 'blood_group', 'rh', 'units', 'status', 'lock_version', 'reporting_period_id', 'voided_at']);
        if (! $row) {
            throw new BloodBankException('BLOOD_BANK_NOT_FOUND', 'التبرع غير موجود في المنشأة المتاحة.', 404);
        }

        return (array) $row;
    }

    public function donations(int $donor, int $facility, array $filters): array
    {
        $this->find('donor', $donor, $facility);
        $q = DB::table('blood_donations as d')->where('d.donor_id', $donor)->where('d.facility_id', $facility);
        if (($filters['search'] ?? '') !== '') {
            $q->whereExists(fn ($codes) => $codes->selectRaw('1')->from('blood_donation_codes as codes')->whereColumn('codes.blood_donation_id', 'd.id')->where('codes.code', 'like', self::like($filters['search'])));
        }
        $page = $q->orderByDesc('d.donated_on')->orderByDesc('d.id')->paginate($filters['per_page'] ?? 20, ['d.id', 'd.donation_code', 'd.donated_on', 'd.blood_group', 'd.rh', 'd.units', 'd.status', 'd.lock_version', 'd.voided_at'], 'page', $filters['page'] ?? 1);

        return ['data' => $page->items(), 'meta' => CatalogQueries::meta($page)];
    }
}
