<?php

namespace App\OpenApi;

use App\Services\Dossiers\DossierAuditHistory;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class DossierAuditDocument extends ClinicDocumentTransformer
{
    public function operation(Operation $op): void
    {
        $s = fn () => new StringType;
        $i = fn () => new IntegerType;
        $change = $this->object(['field' => $s(), 'label' => $s(), 'before' => $s()->nullable(true), 'after' => $s(), 'before_recorded' => new BooleanType]);
        $event = $this->object(['id' => $i(), 'occurred_at' => $s()->format('date-time'), 'actor' => $this->object(['id' => $i()->nullable(true), 'name' => $s()]),
            'visit' => $this->object(['id' => $i(), 'visit_no' => $s(), 'visit_date' => $s()->format('date')])->nullable(true),
            'entity' => $s()->enum(array_keys(DossierAuditHistory::ENTITIES)), 'entity_id' => $i(), 'entity_label' => $s(),
            'action' => $s(), 'action_label' => $s(), 'reason' => $s()->nullable(true), 'changes' => $this->list($change)]);
        $op->description = 'Read-only authoritative audit_logs, scoped through actual dossier/patient/facility/visit FKs; no JSON ownership inference. Requires BOTH dossiers.view and dossiers.audit in the active facility, Sanctum Bearer api ability and active account. All responses private, no-store. facility_id required; optional from/to YYYY-MM-DD are inclusive facility-local audit timestamp days, visit_id must belong to this dossier (404 otherwise), entity and action use the response dictionaries, page>=1, per_page=10|20|50|100 (default 10). Ordered occurred_at DESC, id DESC; total covers all matches. Attachment/upload events and totals additionally require dossiers.attachments.view. Safe field allowlist, no raw snapshots, request bodies, storage keys or binaries. before_recorded=false means no previous value was stored, not a deletion. Directory references are explicitly labelled current names; medication snapshots are historical. saved events normalize to created/updated, nonnull voided_at to voided. No actor directory search is exposed. GET never writes an audit entry.';
        $op->responses = [];
        $entities = $this->object(array_fill_keys(array_keys(DossierAuditHistory::ENTITIES), $s()));
        $entities->required = array_values(array_diff($entities->required, ['dossier_upload', 'visit_attachment']));
        $op->addResponse(Response::make(200)->setDescription('Authorized paginated changes')->setContent('application/json', Schema::fromType($this->object([
            'data' => $this->list($event), 'meta' => $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], $i())),
            'filters' => $this->object(['entities' => $entities, 'actions' => $this->object(array_fill_keys(array_keys(DossierAuditHistory::ACTIONS), $s()))]), 'timezone' => $s(),
        ]))));
        foreach ([401 => 'Unauthenticated', 403 => 'DOSSIER_ACCESS_DENIED; no entries or totals', 404 => 'Dossier or visit outside scope', 422 => 'Invalid filters', 500 => 'DOSSIERS_UNAVAILABLE; no internal details'] as $status => $description) {
            $op->addResponse(Response::make($status)->setDescription($description));
        }
    }
}
