<?php

namespace App\Services\Dossiers;

use Illuminate\Support\Facades\DB;

class DossierVisitSections
{
    public function read(array $f, int $visit): array
    {
        $data = [];
        foreach (['services' => ['service_id', 'performed_by'], 'procedures' => ['procedure_id', 'specialist_id']] as $kind => [$catalog,$doctor]) {
            $data[$kind] = DB::table('visit_'.$kind.' as e')->join($kind.' as n', 'n.id', '=', 'e.'.$catalog)->leftJoin('clinics as c', 'c.id', '=', 'e.clinic_id')->leftJoin('staff as s', 's.id', '=', 'e.'.$doctor)
                ->where('e.facility_id', $f['id'])->where('e.visit_id', $visit)->whereNull('e.voided_at')->orderBy('e.id')
                ->get(['e.id', 'e.lock_version', "e.$catalog as catalog_id", 'n.code', 'n.name_ar', 'e.clinic_id', 'c.name_ar as clinic_name', "e.$doctor as doctor_id", 's.full_name as doctor_name', 'e.note', 'e.performed_on', 'e.quantity'])->all();
        }
        $headers = DB::table('visit_prescriptions as p')->join('clinics as c', 'c.id', '=', 'p.prescribing_clinic_id')->join('staff as s', 's.id', '=', 'p.prescribing_staff_id')->leftJoin('funding_sources as fund', 'fund.id', '=', 'p.funding_source_id')->where('p.facility_id', $f['id'])->where('p.visit_id', $visit)->whereNull('p.voided_at')->orderByRaw("FIELD(p.kind,'unlinked','dose_linked','outside')")->orderBy('p.id')->get(['p.id', 'p.lock_version', 'p.kind', 'p.prescribing_clinic_id', 'p.prescribing_staff_id', 'p.prescribed_on', 'p.note', 'p.funding_source_id', 'fund.name_ar as funding_name', 'p.unavailable_reason', 'c.name_ar as clinic_name', 's.full_name as doctor_name']);
        $data['prescriptions'] = $headers->map(fn ($rx) => (array) $rx + ['items' => DB::table('visit_prescription_items')->where('prescription_id', $rx->id)->where('facility_id', $f['id'])->whereNull('voided_at')->orderBy('display_order')->orderBy('id')->get(['id', 'medication_id', 'medication_code_snapshot as code', 'medication_name_snapshot as name_ar', 'note', 'display_order', 'lock_version'])->all()])->values()->all();
        $data['prescription'] = collect($data['prescriptions'])->firstWhere('kind', 'unlinked');
        $data['outcome'] = DB::table('visit_outcomes as o')->join('visit_results as r', 'r.id', '=', 'o.result_id')->leftJoin('clinics as c', 'c.id', '=', 'o.clinic_id')->join('staff as s', 's.id', '=', 'o.decided_by')->where('o.visit_id', $visit)->where('o.facility_id', $f['id'])->whereNull('o.voided_at')->first(['o.id', 'o.lock_version', 'r.code', 'r.name_ar', 'o.clinic_id', 'c.name_ar as clinic_name', 'o.decided_by as doctor_id', 's.full_name as doctor_name', 'o.outcome_on', 'o.referral_target', 'o.outgoing_referral_date', 'o.outgoing_referral_reason', 'o.note']);
        $data['attachment_count'] = $f['capabilities']['attachments_view'] ? DB::table('visit_attachments')->where('facility_id', $f['id'])->where('visit_id', $visit)->whereNull('voided_at')->count() : null;

        return $data;
    }
}
