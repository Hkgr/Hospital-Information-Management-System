<?php

namespace App\OpenApi;

use App\Services\Dossiers\OncologyQueries;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class OncologyDocument extends ClinicDocumentTransformer
{
    public function operation(Operation $op, string $route): void
    {
        if ($op->method !== 'get') {
            $this->request($op, $route);
        }
        $op->description = 'Oncology workflow within the facility Patient Card medical context. Active-account Sanctum Bearer api ability, explicit facility_id, dossiers.view and dossiers.treatment.view are required. Writes additionally require treatment.create/update/schedule/administer/dispense/correct/void as appropriate. Permission definitions are never automatically assigned. UUID request_id is scoped to actor, facility and operation; changed replay payload or stale lock_version returns 409. Plan edits append immutable revisions; omitted optional values retain prior data. Every plan is effective as soon as it is saved; diagnostic readiness does not pause it. Scheduled dates are explicitly confirmed and create no visits. Reauthorization requires reason and records actor/time. Actual doses require session_id, session_lock_version, plan_lock_version, visit_lock_version; corrections require lock_version and reason. Actual administration and dispensing dates equal the nonfuture selected visit date. Medication administration and dispensing do not require a reporting period. If reporting_period_id is sent it must be an open period in this facility covering the actual date; omitting it on an update keeps any previously stored period. Plans and appointments need no period. Medication funding uses medication_source, the same codes as the patient-card general medication source. Item omissions preserve history; remove requires saved item ID/version/void_reason. Dispensing purpose is unlinked (no dose; prescribing_clinic_id required), take_home (outside the hospital; no dose; prescribing_clinic_id required) or supportive (dose_session_id; recorded only while recording that dose). Dispensing never creates stock movement. Fixed numeric routes only. Reads are paginated for plans/sessions; detail includes immutable revisions with medication snapshots. Private, no-store responses. Planned, prescribed, administered and dispensed medications are separate facts.';
        $op->description .= ' New administrations require session.planned_on = visit.visit_date = administered_on and session.revision_id = plan.current_revision_id. Different dates require an explicit audited reschedule. Session resolution requires plan_lock_version and session lock_version; carry_forward=true is allowed only for obsolete sessions with status=rescheduled, explicit planned_on and reason; it adopts the current revision and the treating clinic/doctor. Every rescheduled status requires planned_on, including carry-forward. Terminal statuses reject carry_forward and preserve the old revision, clinician and planned date. Session reads expose a boolean has_voided_dose independently from the active dose_id/visit_id; historical attempts do not multiply rows. Dose dates are explicit and are not limited by a plan window, cycle count or session count. Full-dose void requires treatment.void AND treatment.schedule, session_lock_version, plan_lock_version, reason and session_resolution=rescheduled|missed|cancelled|referred; rescheduled additionally requires planned_on and explicit carry_forward for obsolete revisions. Voiding and resolution are atomic; dispensing remains independent. Only one active dose per session; voided attempts retain their immutable revision. Exact normalized plan duplicates return error.existing_plan_id within the authorized scope; intentional duplicates require confirm_duplicate=true plus duplicate_reason. No-op amendments create no revision. Patient Card reports preserve and label parent and item void states; selected-visit reports exclude invalidated actual facts.';
        $op->description .= ' A plan records modality, intent, protocol_text, and two clinic doctors: protocol_doctor_id (who wrote the protocol) and treating_doctor_id (the treating physician). It has no regimen lines, diagnosis link, date window or cycle metadata. A plan is several appointments. POST .../sessions stores those appointments as sessions; omitted session_number values are assigned in order on the treating doctor. Each session then has one or more treatment doses via POST .../treatment-sessions/{session}/session-doses: given_on (defaults in the client to the appointment date), dose_name, complaint, recommendations, nurse_id from that session clinic, and medication_source using the same five codes as the patient-card general medication source. The client defaults medication_source to the dossier value and the user may change it. That dose is not a visit administration. POST /dossiers/{dossier}/treatment-sessions records an independent appointment with planned_on, clinic_id, doctor_id and optional note; dossiers.treatment.schedule is required. It creates no plan, visit, administration or dispensing. Its plan_id/revision_id/session_number are null; rescheduling requires its lock_version but no plan_lock_version. carry_forward is forbidden for independent appointments. Plan-backed sessions still require plan_lock_version. Actual administration retains the approved-plan safety requirements.';
        foreach ([401 => 'Unauthenticated', 403 => 'Permission, facility, account or ability refused', 404 => 'Scoped record not found', 409 => 'DOSSIER_VERSION_CONFLICT, changed UUID replay, or ONCOLOGY_DUPLICATE_PLAN (error.existing_plan_id)', 422 => 'ONCOLOGY_SCHEDULE_DATE_MISMATCH, ONCOLOGY_OBSOLETE_SESSION_REVISION, ONCOLOGY_NO_CLINICAL_CHANGE, ONCOLOGY_INVALID_VOID_RESOLUTION, ONCOLOGY_INVALID_CARRY_FORWARD, ONCOLOGY_RESCHEDULE_DATE_REQUIRED; or invalid clinical, period or item fields', 500 => 'DOSSIERS_UNAVAILABLE; no internals'] as $code => $label) {
            $op->addResponse(Response::make($code)->setDescription($label));
        }
    }

    private function request(Operation $op, string $route): void
    {
        $s = fn () => new StringType;
        $i = fn () => new IntegerType;
        $date = fn () => (new StringType)->format('date');
        $fields = ['facility_id' => $i(), 'request_id' => $s()->format('uuid'), 'lock_version' => $i()];
        $required = ['facility_id', 'request_id'];
        $item = $this->object(['id' => $i(), 'lock_version' => $i(), 'remove' => new BooleanType, 'void_reason' => $s(), 'medication_id' => $i()->nullable(true), 'medication_name_snapshot' => $s()->nullable(true), 'medication_code_snapshot' => $s()->nullable(true), 'dose_value' => $s(), 'dose_unit' => $s(), 'route' => $s(), 'instructions' => $s(), 'funding_source_id' => $i()->nullable(true), 'medication_source' => $s()->enum(array_keys(OncologyQueries::MEDICATION_SOURCES))->setDescription('Required when saving a medication line. Same codes as the dossier general medication source. Omitted on update retains the stored code.'), 'quantity' => $s(), 'quantity_unit' => $s(), 'dose_text' => $s(), 'note' => $s()->nullable(true)]);
        $item->required = [];
        if (str_ends_with($route, '/void')) {
            $fields['reason'] = $s();
            $required = [...$required, 'lock_version', 'reason'];
            if (str_contains($route, '/doses/')) {
                $fields += ['session_resolution' => $s()->enum(['rescheduled', 'missed', 'cancelled', 'referred']), 'planned_on' => $date()->setDescription('Required whenever session_resolution=rescheduled, including carry-forward. Never inferred from the old date.'), 'carry_forward' => (new BooleanType)->setDescription('Only an obsolete session with explicit rescheduled resolution and planned_on may be carried forward.'), 'session_lock_version' => $i(), 'plan_lock_version' => $i()];
                $required = [...$required, 'session_resolution', 'session_lock_version', 'plan_lock_version'];
            }
        } elseif (str_ends_with($route, '/status')) {
            $fields += ['status' => $s()->enum(['active'])->setDescription('Plans stay effective. Any other status is rejected.'), 'reason' => $s(), 'override_reason' => $s()->nullable(true)];
            $required = [...$required, 'lock_version', 'status', 'reason'];
        } elseif (str_contains($route, '/session-doses')) {
            $fields += ['given_on' => $date()->setDescription('Defaults in the client to the session appointment date; stored as sent.'), 'dose_name' => $s(), 'complaint' => $s(), 'recommendations' => $s(), 'nurse_id' => $i(), 'medication_source' => $s()->enum(array_keys(OncologyQueries::MEDICATION_SOURCES))->setDescription('Required when creating a treatment dose. Same codes as the dossier general medication source. The client defaults to that stored value and the user may change it. Omitted on update retains the stored code.')];
            $required = [...$required, 'given_on', 'dose_name', 'complaint', 'recommendations', 'nurse_id'];
            if ($op->method === 'post') {
                $required[] = 'medication_source';
            }
            if ($op->method === 'put') {
                $required[] = 'lock_version';
            }
        } elseif (str_ends_with($route, '/treatment-sessions')) {
            $fields += ['planned_on' => $date(), 'clinic_id' => $i(), 'doctor_id' => $i(), 'note' => $s()->nullable(true)];
            $required = [...$required, 'planned_on', 'clinic_id', 'doctor_id'];
        } elseif (str_ends_with($route, '/sessions')) {
            $session = $this->object(['planned_on' => $date(), 'session_number' => $i()->nullable(true), 'note' => $s()->nullable(true)]);
            $session->required = ['planned_on'];
            $fields['sessions'] = $this->list($session);
            $required = [...$required, 'lock_version', 'sessions'];
        } elseif (str_contains($route, '/treatment-sessions/')) {
            $fields += ['status' => $s()->enum(['rescheduled', 'missed', 'cancelled', 'referred']), 'reason' => $s(), 'planned_on' => $date()->setDescription('Required whenever status=rescheduled, including carry-forward. Never inferred from the old date.'), 'carry_forward' => (new BooleanType)->setDescription('Only an obsolete session with status=rescheduled and explicit planned_on may be carried forward.'), 'plan_lock_version' => $i()];
            $fields['plan_lock_version']->setDescription('Required for plan-backed sessions only; omit for independent appointments.');
            $required = [...$required, 'lock_version', 'status', 'reason'];
        } elseif (str_contains($route, '/doses')) {
            $fields += ['reporting_period_id' => $i()->nullable(true)->setDescription('Optional. When sent, the period must be open and cover the administration date.'), 'administered_on' => $date(), 'supervising_staff_id' => $i(), 'administered_by' => $i(), 'session_label' => $s()->nullable(true), 'note' => $s()->nullable(true), 'items' => $this->list($item), 'reason' => $s()];
            $required = [...$required, 'administered_on', 'supervising_staff_id', 'administered_by', 'items'];
            if ($op->method === 'post') {
                foreach (['session_id', 'session_lock_version', 'plan_lock_version', 'visit_lock_version'] as $key) {
                    $fields[$key] = $i();
                    $required[] = $key;
                }
            } else {
                $required = [...$required, 'lock_version', 'reason'];
            }
        } elseif (str_contains($route, '/dispensing')) {
            $fields += array_intersect_key($item->properties, array_flip(['medication_id', 'medication_name_snapshot', 'medication_code_snapshot', 'funding_source_id', 'medication_source', 'dose_text', 'quantity', 'quantity_unit', 'note'])) + ['reporting_period_id' => $i()->nullable(true)->setDescription('Optional. When sent, the period must be open and cover the dispensing date.'), 'dispensed_on' => $date(), 'prescribing_staff_id' => $i(), 'prescribing_clinic_id' => $i()->setDescription('Required for unlinked and take_home dispensing, which are not linked to a dose.'), 'dose_session_id' => $i()->setDescription('Required only for supportive dispensing recorded with a dose. Prohibited when creating unlinked or take_home dispensing.'), 'dispensing_purpose' => $s()->enum(['unlinked', 'take_home', 'supportive'])->setDescription('unlinked: dispensed without a dose. take_home: dispensed outside the hospital. supportive: linked to a dose and recorded only while recording that dose.'), 'reason' => $s()];
            $required = [...$required, 'dispensed_on', 'prescribing_staff_id', 'dispensing_purpose', 'quantity', 'quantity_unit'];
            if ($op->method === 'post') {
                $required[] = 'medication_source';
            }
            if ($op->method === 'put') {
                $required = [...$required, 'lock_version', 'reason'];
            }
        } else {
            $fields += ['confirm_duplicate' => new BooleanType, 'duplicate_reason' => $s()];
            $fields += ['modality' => $s()->enum(array_keys(OncologyQueries::MODALITIES)), 'intent' => $s()->enum(array_keys(OncologyQueries::INTENTS)), 'protocol_text' => $s()->setDescription('Long treatment-protocol text.'), 'protocol_clinic_id' => $i(), 'protocol_doctor_id' => $i(), 'treating_clinic_id' => $i(), 'treating_doctor_id' => $i()];
            $required = [...$required, 'modality', 'intent', 'protocol_text', 'protocol_clinic_id', 'protocol_doctor_id', 'treating_clinic_id', 'treating_doctor_id'];
            if ($op->method === 'put') {
                $required[] = 'lock_version';
            }
        }
        $body = $this->object($fields);
        $body->required = $required;
        $op->requestBodyObject = (new RequestBodyObject)->required()->setContent('application/json', Schema::fromType($body));
    }
}
