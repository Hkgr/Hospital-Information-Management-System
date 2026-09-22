<?php

namespace App\OpenApi;

use App\Services\Audit\SystemLogHistory;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class AuditLogDocument extends ClinicDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if ($route !== 'audit') {
                continue;
            }
            foreach ($path->operations as $op) {
                $op->security = [new SecurityRequirement(['bearerAuth' => []])];
                $this->operation($op);
            }
        }
    }

    public function operation(Operation $op): void
    {
        $s = fn () => new StringType;
        $i = fn () => new IntegerType;
        $change = $this->object(['field' => $s(), 'label' => $s(), 'before' => $s()->nullable(true), 'after' => $s(), 'before_recorded' => new BooleanType]);
        $actor = $this->object(['id' => $i()->nullable(true), 'name' => $s()]);
        $event = $this->object(['id' => $i(), 'occurred_at' => $s()->format('date-time'), 'actor' => $actor,
            'category' => $s()->enum(array_keys(SystemLogHistory::CATEGORIES)), 'category_label' => $s(),
            'entity' => $s(), 'entity_label' => $s(), 'entity_id' => $i(), 'action' => $s(), 'action_label' => $s(),
            'reason' => $s()->nullable(true), 'changes' => $this->list($change)]);
        $op->description = 'Facility-scoped read of audit_logs for the system activity page. Requires Sanctum Bearer api ability, an active account, and membership in facility_id. No new permission is created or assigned. GET never writes. Technical errors are stored as entity_type=system_error with a redacted message. Login and logout are stored as auth_session when the user has a facility. Allowlisted field projection only.';
        $op->responses = [];
        $op->addResponse(Response::make(200)->setDescription('Authorized paginated activity')->setContent('application/json', Schema::fromType($this->object([
            'data' => $this->list($event), 'meta' => $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], $i())),
            'filters' => $this->object([
                'categories' => $this->object(array_fill_keys(array_keys(SystemLogHistory::CATEGORIES), $s())),
                'entities' => $this->object(array_fill_keys(array_keys(SystemLogHistory::ENTITIES), $s())),
                'actions' => $this->object(array_fill_keys(array_keys(SystemLogHistory::ACTIONS), $s())),
            ]), 'timezone' => $s(),
        ]))));
        foreach ([401 => 'Unauthenticated', 403 => 'FACILITY_ACCESS_DENIED; no entries or totals', 422 => 'Invalid filters', 500 => 'AUDIT_UNAVAILABLE; no internal details'] as $status => $description) {
            $op->addResponse(Response::make($status)->setDescription($description));
        }
    }
}
