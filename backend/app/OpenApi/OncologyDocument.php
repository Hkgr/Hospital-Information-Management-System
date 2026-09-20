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
        $op->description = 'Oncology workflow within the facility Patient Card medical context. Active-account Sanctum Bearer api ability, explicit facility_id, dossiers.view and dossiers.treatment.view are required. Writes additionally require treatment.create/update/activate/override/status/schedule/administer/dispense/correct/void as appropriate. Permission definitions are never automatically assigned. UUID request_id is scoped to actor, facility and operation; changed replay payload or stale lock_version returns 409. Plan edits append immutable revisions; omitted optional values retain prior data. Scheduled dates are explicitly confirmed and create no visits. Effective status is needs_review when activation evidence changes; new administration requires current active readiness. pathology_not_required requires treatment.override plus override_reason and responsible doctor. Reauthorization requires reason and records actor/time. Actual doses require session_id, session_lock_version, plan_lock_version, visit_lock_version; corrections require lock_version and reason. Actual administration and dispensing dates equal the nonfuture selected visit date. Explicit open reporting_period_id must cover actual facts; plans and appointments need no period. Item omissions preserve history; remove requires saved item ID/version/void_reason. Dispensing uses dose_session_id, dispensing_purpose=take_home|supportive and never creates stock movement. Fixed numeric routes only. Reads are paginated for plans/sessions; detail includes immutable revisions with medication snapshots. Private, no-store responses. Planned, prescribed, administered and dispensed medications are separate facts.';
        foreach ([401 => 'Unauthenticated', 403 => 'Permission, facility, account or ability refused', 404 => 'Scoped record not found', 409 => 'DOSSIER_VERSION_CONFLICT or changed UUID replay', 422 => 'Invalid clinical state, date, period, readiness or item fields', 500 => 'DOSSIERS_UNAVAILABLE; no internals'] as $code => $label) {
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
        $item = $this->object(['id' => $i(), 'lock_version' => $i(), 'remove' => new BooleanType, 'void_reason' => $s(), 'medication_id' => $i()->nullable(true), 'medication_name_snapshot' => $s()->nullable(true), 'medication_code_snapshot' => $s()->nullable(true), 'dose_value' => $s(), 'dose_unit' => $s(), 'route' => $s(), 'instructions' => $s(), 'funding_source_id' => $i()->nullable(true), 'quantity' => $s(), 'quantity_unit' => $s(), 'dose_text' => $s(), 'note' => $s()->nullable(true)]);
        $item->required = [];
        if (str_ends_with($route, '/void')) {
            $fields['reason'] = $s();
            $required = [...$required, 'lock_version', 'reason'];
        } elseif (str_ends_with($route, '/status')) {
            $fields += ['status' => $s()->enum(['active', 'paused', 'completed', 'cancelled']), 'reason' => $s(), 'override_reason' => $s()->nullable(true)];
            $required = [...$required, 'lock_version', 'status', 'reason'];
        } elseif (str_ends_with($route, '/sessions')) {
            $session = $this->object(['planned_on' => $date(), 'session_number' => $i(), 'cycle_number' => $i()->nullable(true), 'note' => $s()->nullable(true)]);
            $session->required = ['planned_on', 'session_number'];
            $fields['sessions'] = $this->list($session);
            $required = [...$required, 'lock_version', 'sessions'];
        } elseif (str_contains($route, '/treatment-sessions/')) {
            $fields += ['status' => $s()->enum(['rescheduled', 'missed', 'cancelled', 'referred']), 'reason' => $s(), 'planned_on' => $date()];
            $required = [...$required, 'lock_version', 'status', 'reason'];
        } elseif (str_contains($route, '/doses')) {
            $fields += ['reporting_period_id' => $i(), 'administered_on' => $date(), 'supervising_staff_id' => $i(), 'administered_by' => $i(), 'session_label' => $s()->nullable(true), 'note' => $s()->nullable(true), 'items' => $this->list($item), 'reason' => $s()];
            $required = [...$required, 'reporting_period_id', 'administered_on', 'supervising_staff_id', 'administered_by', 'items'];
            if ($op->method === 'post') {
                foreach (['session_id', 'session_lock_version', 'plan_lock_version', 'visit_lock_version'] as $key) {
                    $fields[$key] = $i();
                    $required[] = $key;
                }
            } else {
                $required = [...$required, 'lock_version', 'reason'];
            }
        } elseif (str_contains($route, '/dispensing')) {
            $fields += array_intersect_key($item->properties, array_flip(['medication_id', 'medication_name_snapshot', 'medication_code_snapshot', 'funding_source_id', 'dose_text', 'quantity', 'quantity_unit', 'note'])) + ['reporting_period_id' => $i(), 'dispensed_on' => $date(), 'prescribing_staff_id' => $i(), 'dose_session_id' => $i(), 'dispensing_purpose' => $s()->enum(['take_home', 'supportive']), 'reason' => $s()];
            $required = [...$required, 'reporting_period_id', 'dispensed_on', 'prescribing_staff_id', 'dose_session_id', 'dispensing_purpose', 'quantity', 'quantity_unit'];
            if ($op->method === 'put') {
                $required = [...$required, 'lock_version', 'reason'];
            }
        } else {
            $fields += ['modality' => $s()->enum(array_keys(OncologyQueries::MODALITIES)), 'intent' => $s()->enum(array_keys(OncologyQueries::INTENTS)), 'protocol_name' => $s(), 'protocol_code' => $s()->nullable(true), 'clinic_id' => $i(), 'doctor_id' => $i(), 'diagnosis_id' => $i()->nullable(true), 'starts_on' => $date(), 'ends_on' => $date()->nullable(true), 'planned_cycles' => $i()->nullable(true), 'planned_sessions' => $i()->nullable(true), 'interval_days' => $i()->nullable(true), 'note' => $s()->nullable(true), 'amendment_reason' => $s()->nullable(true), 'items' => $this->list($item)];
            $required = [...$required, 'modality', 'intent', 'protocol_name', 'clinic_id', 'doctor_id', 'starts_on', $op->method === 'put' ? 'lock_version' : 'items'];
        }
        $body = $this->object($fields);
        $body->required = $required;
        $op->requestBodyObject = (new RequestBodyObject)->required()->setContent('application/json', Schema::fromType($body));
    }
}
