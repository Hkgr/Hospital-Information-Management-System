<?php

namespace App\OpenApi;

use App\Services\Dossiers\DossierPathology;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class DossierPathologyDocument extends ClinicDocumentTransformer
{
    public function assessment(): ObjectType
    {
        $props = ['id' => new IntegerType, 'lock_version' => new IntegerType, 'disposition' => (new StringType)->enum(array_slice(array_keys(DossierPathology::DISPOSITIONS), 0, 6)), 'effective_disposition' => new StringType, 'needs_review' => new BooleanType];
        foreach (['assessed_on', 'note', 'required_reason', 'not_required_reason', 'follow_up'] as $key) {
            $props[$key] = (new StringType)->nullable(true);
        }
        foreach (['evidence_pathology_id', 'clinic_id', 'doctor_id'] as $key) {
            $props[$key] = (new IntegerType)->nullable(true);
        }

        return $this->object($props);
    }

    public function operation(Operation $op, string $route): void
    {
        $assessment = str_ends_with($route, '/diagnostic-assessment');
        $op->description = 'Facility-scoped Patient Card diagnostic workflow. Requires active account, Sanctum Bearer api ability, explicit active facility_id and dossiers.view. Reads never infer pathology from diagnoses. Writes additionally require dossiers.assessment.update or dossiers.pathology.create/update/void. UUID request_id is replay-safe; changed payload reuse and stale lock_version return 409 atomically. Assessment creation uses lock_version=0. Pathology additions/corrections can follow completion of a real visit; no service or procedure is created. Unknown dates remain null; external reports may predate context opening, occurred-event dates cannot be future in facility timezone. Completed pathology requires result_on and conclusion; external source requires external_organization. Cancelled/unavailable requires unavailable_reason; void requires void_reason. Omitted cases/fields/attachment links preserve history. Attachment IDs require separate attachments.view and must be completed nonvoided attachments on the same visit. No storage keys or binaries are returned. Confirmed assessment must reference completed nonvoided evidence in this facility medical context; effective_disposition becomes not_assessed/needs_review if evidence is withdrawn. referred_out requires the existing nonvoided DOS-REFER outcome. Private, no-store on every response.';
        $op->description .= ' After merging omitted fields, the server clears incompatible decision reasons/evidence, internal external_organization, unavailable_reason outside unavailable/cancelled, and final-result fields outside completed. Completed reports permit audited corrections but cannot downgrade (422); use void with reason, then explicitly create a corrected case. Known assessment and internal event dates must be on/after the source visit. External historical dates may predate the visit. Unrelated omitted notes, follow-up, responsibility and attachment history are preserved.';
        $s = fn () => (new StringType)->nullable(true);
        $row = $this->object(['id' => new IntegerType, 'visit_id' => new IntegerType, 'visit_no' => new StringType, 'visit_date' => new StringType, 'lock_version' => new IntegerType, 'source' => (new StringType)->enum(['internal', 'external']), 'status' => (new StringType)->enum(array_keys(DossierPathology::STATUSES))]);
        foreach (['report_number', 'external_organization', 'specimen_type', 'anatomical_site', 'requested_on', 'collected_on', 'result_on', 'conclusion', 'note', 'unavailable_reason', 'voided_at', 'void_reason'] as $key) {
            $row->addProperty($key, $s());
        }
        foreach (['procedure_event_id', 'clinic_id', 'doctor_id'] as $key) {
            $row->addProperty($key, (new IntegerType)->nullable(true));
        }
        $row->addProperty('attachments', $this->list($this->object(['id' => new IntegerType, 'visit_id' => new IntegerType, 'title' => new StringType, 'original_filename' => new StringType, 'voided_at' => $s(), 'void_reason' => $s()])));
        $row->addProperty('capabilities', $this->object(['update' => new BooleanType, 'void' => new BooleanType]));
        $collection = str_ends_with($route, '/pathology');
        $body = $assessment ? $this->object(['data' => $this->assessment()->nullable(true)]) : ($op->method !== 'get' ? $this->object(['data' => $this->object(['id' => new IntegerType])]) : ($collection ? $this->object(['data' => $this->list($row), 'meta' => $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], new IntegerType))]) : $this->object(['data' => $row])));
        $op->responses = [];
        $op->addResponse(Response::make($op->method === 'post' && $collection ? 201 : 200)->setDescription('Authorized diagnostic workflow response')->setContent('application/json', Schema::fromType($body)));
        foreach ([401 => 'Unauthenticated', 403 => 'Permission, ability, account or facility denied', 404 => 'Record outside patient/context/visit scope', 409 => 'DOSSIER_VERSION_CONFLICT', 422 => 'Invalid medical fields or evidence', 500 => 'DOSSIERS_UNAVAILABLE; no internal details'] as $code => $description) {
            $op->addResponse(Response::make($code)->setDescription($description));
        }
    }
}
