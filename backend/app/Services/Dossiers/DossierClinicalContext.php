<?php

namespace App\Services\Dossiers;

use App\Services\Clinics\ClinicCounts;
use App\Services\Directory\ClinicStaffLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DossierClinicalContext
{
    public const TABLES = [
        'visit_diagnoses' => ['clinic_id', 'diagnosing_staff_id', 'diagnoses'],
        'visit_services' => ['clinic_id', 'performed_by', 'services'],
        'visit_procedures' => ['clinic_id', 'specialist_id', 'procedures'],
        'visit_prescriptions' => ['prescribing_clinic_id', 'prescribing_staff_id', 'prescription'],
        'visit_outcomes' => ['clinic_id', 'decided_by', 'outcome'],
    ];

    /** Read before opening the transaction; the locked visit version checks stale targets. */
    public function targets(int $visit, array $f, array $input = []): array
    {
        $staff = [];
        $clinics = [];
        foreach (self::TABLES as $table => [$clinic, $doctor]) {
            foreach (DB::table($table)->where('visit_id', $visit)->where('facility_id', $f['id'])->whereNull('voided_at')->get([$clinic, $doctor]) as $row) {
                $clinics[] = $row->$clinic;
                $staff[] = $row->$doctor;
            }
        }
        $walk = function (array $rows) use (&$walk, &$staff, &$clinics) {
            foreach ($rows as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                } elseif (in_array($key, ['clinic_id', 'prescribing_clinic_id'])) {
                    $clinics[] = $value;
                } elseif (in_array($key, ['doctor_id', 'diagnosing_staff_id', 'prescribing_staff_id', 'attending_staff_id'])) {
                    $staff[] = $value;
                }
            }
        };
        $walk($input);

        return [array_filter($staff), array_filter($clinics)];
    }

    public function lock(array $targets): void
    {
        $links = app(ClinicStaffLinks::class);
        $links->lockStaff($targets[0]);
        $links->lockClinics($targets[1]);
    }

    public function check(array $f, ?int $clinic, ?int $doctor, string $date, string $field, bool $historical): void
    {
        $q = $historical
            ? DB::table('clinics as c')->join('clinic_staff as cs', 'cs.clinic_id', '=', 'c.id')->where('c.facility_id', $f['id'])->where('cs.staff_id', $doctor)->where('cs.starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('cs.ends_on')->orWhere('cs.ends_on', '>', $date))
            : app(ClinicCounts::class)->currentDoctors(array_replace($f, ['today' => $date]))->where('s.id', $doctor);
        if (! $clinic || ! $doctor || ! $q->where('c.id', $clinic)->exists()) {
            throw ValidationException::withMessages([$field => 'ارتباط الطبيب بالعيادة في هذا المشفى لا يغطي تاريخ الزيارة، أو أن الاختيار الجديد غير فعال. راجع هذا السطر؛ لم تُحفظ أي تغييرات.']);
        }
    }

    public function retained(array $f, int $visit, string $date, bool $diagnoses = true): void
    {
        foreach (self::TABLES as $table => [$clinic, $doctor, $field]) {
            if (! $diagnoses && $table === 'visit_diagnoses') {
                continue;
            }
            foreach (DB::table($table)->where('visit_id', $visit)->where('facility_id', $f['id'])->whereNull('voided_at')->orderBy('id')->lockForUpdate()->get() as $index => $row) {
                // Legacy events without a dossier clinic retain their independent
                // historical contract. Finalization still requires explicit review.
                if (! $diagnoses && property_exists($row, 'dossier_managed') && ! $row->dossier_managed && ! $row->$clinic) {
                    continue;
                }
                $this->check($f, $row->$clinic, $row->$doctor, $date, "$field.$index.doctor_id", true);
            }
        }
    }
}
