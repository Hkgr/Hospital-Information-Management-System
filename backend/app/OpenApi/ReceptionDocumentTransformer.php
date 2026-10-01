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
            $route = trim(preg_replace('#^/?api/#', '', $path->path), '/');
            if (! str_starts_with($route, 'reception/') && ! str_starts_with($route, 'patient-cards/')) {
                continue;
            }
            foreach ($path->operations as $op) {
                $op->security = [new SecurityRequirement(['bearerAuth' => []])];
                if (str_contains($route, 'reviews/accounts')) {
                    $op->description = 'Retired account management route. Always returns 410 RECEPTION_ACCOUNTS_RETIRED for authenticated active API sessions; never changes accounts, assignments or tokens. Use the authorized users API.';
                    $op->addResponse(Response::make(410)->setDescription('RECEPTION_ACCOUNTS_RETIRED'));

                    continue;
                }
                if (str_contains($path->path, '/reviews/') || str_ends_with($path->path, '/identity') || str_ends_with($path->path, '/{action}')) {
                    $op->description = 'Patient-card identity review. /patient-cards is canonical; /reception aliases remain compatible. Active-account Sanctum Bearer api ability; explicit active facility_id on every request; private, no-store. GET identity requires patients.basic.view and returns only allowlisted identity values, versions, server deadline and own request states. POST correct requires patients.own.correct: original author, originally entered allowlisted fields, unchanged independent scope and server time strictly before saved_at + 15 minutes. POST corrections requires patients.own.corrections.request and changes no identity. Both require request_id UUID, patient_version, dossier_version, reason, changes. Review corrections requires facility identity_corrections.review; approval additionally requires GLOBAL patients.identity.review. Duplicates/patients/preview require patient_duplicates.review; duplicate approval additionally requires GLOBAL patients.duplicates.merge. Preview returns a fingerprint; request binds canonical/duplicate dossier IDs and preview_hash. No clinical facts move; any independent duplicate reference blocks merge. Decision requires request_id, lock_version, decision approved|rejected and reason; changed identity/context returns 409, never automatic rebase. Account management is available only under users; the former reception account endpoints are retired. All writes transactional, versioned, idempotent and audited. Reused UUID with different content fails.';
                    foreach ([401 => 'Authentication required', 403 => 'Facility or explicit local/global authority denied', 404 => 'Record unavailable in selected facility', 409 => 'Version, scope, deadline, duplicate safety or UUID conflict; review required', 422 => 'Invalid allowlisted fields or decision'] as $status => $description) {
                        $op->addResponse(Response::make($status)->setDescription($description));
                    }

                    continue;
                }
                $op->description = 'Direct patient-card entry. Active-account Sanctum Bearer api ability and explicit active facility patients.basic.view required. /patient-cards is canonical; /reception aliases share the same writer and UUID operation namespace. Search is locally bounded to patients with a dossier or visit in the facility, or the caller-owned unattached identity. Explicit GLOBAL patients.basic.search permits broader minimal identity lookup; no global grant is implied by local access. Normalized search minimum 3 characters, maximum 10 matches, no totals, literal % and _, 30 requests/minute/user. Registration requires local patient_cards.register with patients.basic.view; no additional global delegation for this bounded card operation. Existing patient IDs are scope-checked again under the patient lock. Reuses the personal writer transaction, UUID idempotency, canonical PC sequence, unique patient/facility context and first draft visit. Send section-personal fields, opening_date and visit_date; never send code, lock_version, medical data, smoking_status or alcohol_status. Existing mode sends patient_id and no copied demographics. Codes are system identifiers, not national IDs. A new registration creates a draft card/context and initial draft visit atomically; no medical completion, deletion, report or subsequent visit API. Responses contain only identification/status/registration identifiers, never medical history, diagnoses, treatment, attachments, or reports. Successful GET card summaries create an opened audit event with actor/facility/card/time only plus surface and optional visit ID. All responses private, no-store. Definitions seeder assigns no users; super admin requires an audited protected global role assignment, never a user-ID bypass.';
                foreach ([401 => 'Authentication required', 403 => 'Facility, account, API ability or permission denied', 404 => 'Card not found within the authorized facility', 409 => 'UUID content mismatch or existing local card; never duplicate or silently merge', 422 => 'Invalid registration or dates', 429 => 'Search rate exceeded'] as $status => $description) {
                    $op->addResponse(Response::make($status)->setDescription($description));
                }
            }
        }
    }
}
