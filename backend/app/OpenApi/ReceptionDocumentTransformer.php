<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;

class ReceptionDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        foreach ($document->paths as $path) {
            if (! str_starts_with(trim(preg_replace('#^/?api/#', '', $path->path), '/'), 'reception/')) {
                continue;
            }
            foreach ($path->operations as $op) {
                $op->security = [new SecurityRequirement(['bearerAuth' => []])];
                $op->description = 'Limited reception only. Active-account Sanctum Bearer api ability and explicit active facility reception.view required. Search requires separate GLOBAL reception.patients.search; normalized search minimum 3 characters, maximum 10 matches, no totals, literal % and _, 30 requests/minute/user. Registration additionally requires facility reception.register and GLOBAL reception.patients.create (new) or reception.patients.search (existing). Reuses the personal writer transaction, UUID idempotency, canonical PC sequence, unique patient/facility context and first draft visit. Send section-personal fields, opening_date and visit_date; never send code, lock_version, medical data, smoking_status or alcohol_status. Existing mode sends patient_id and no copied demographics. Codes are system identifiers, not national IDs. A new registration creates a draft card/context and initial draft visit atomically; no medical completion, deletion, report or subsequent visit API. Responses contain only identification/status/registration identifiers, never medical history, diagnoses, treatment, attachments, or reports. Successful GET card summaries create an opened audit event with actor/facility/card/time only plus surface and optional visit ID. All responses private, no-store. Definitions seeder assigns no users; super admin requires an audited protected global role assignment, never a user-ID bypass.';
                foreach ([401 => 'Authentication required', 403 => 'Facility, account, API ability or permission denied', 404 => 'Card not found within the authorized facility', 409 => 'UUID content mismatch or existing local card; never duplicate or silently merge', 422 => 'Invalid registration or dates', 429 => 'Search rate exceeded'] as $status => $description) {
                    $op->addResponse(Response::make($status)->setDescription($description));
                }
            }
        }
    }
}
