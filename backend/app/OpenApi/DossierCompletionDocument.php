<?php

namespace App\OpenApi;

use App\Services\Dossiers\DossierReports;
use Database\Seeders\DossierOutcomeSeeder;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class DossierCompletionDocument extends ClinicDocumentTransformer
{
    public static function matches(string $route): bool
    {
        return $route === 'dossiers/medications' || preg_match('#/(clinical|medications|review|complete|uploads|attachments|export|report|subsequent|new)(/|$)#', $route) === 1;
    }

    public function clinical(): ObjectType
    {
        $s = fn () => (new StringType)->nullable(true);
        $i = fn () => new IntegerType;
        $row = $this->object(['id' => $i(), 'lock_version' => $i(), 'catalog_id' => $i(), 'code' => $s(), 'name_ar' => $s(), 'clinic_id' => $i()->nullable(true), 'clinic_name' => $s(), 'doctor_id' => $i()->nullable(true), 'doctor_name' => $s(), 'note' => $s()]);
        $rx = $this->object(['id' => $i(), 'lock_version' => $i(), 'prescribing_clinic_id' => $i(), 'prescribing_staff_id' => $i(), 'clinic_name' => $s(), 'doctor_name' => $s(), 'prescribed_on' => $s(), 'note' => $s(), 'items' => $this->list($this->object(['id' => $i(), 'lock_version' => $i(), 'medication_id' => $i(), 'code' => $s(), 'name_ar' => $s(), 'note' => $s(), 'display_order' => $i()]))])->nullable(true);
        $out = $this->object(['id' => $i(), 'lock_version' => $i(), 'code' => $s(), 'name_ar' => $s(), 'clinic_id' => $i(), 'doctor_id' => $i(), 'clinic_name' => $s(), 'doctor_name' => $s(), 'outcome_on' => $s(), 'note' => $s(), 'referral_target' => $s(), 'outgoing_referral_date' => $s(), 'outgoing_referral_reason' => $s()])->nullable(true);

        return $this->object(['services' => $this->list($row), 'procedures' => $this->list($row), 'prescription' => $rx, 'outcome' => $out, 'attachment_count' => $i()->nullable(true)]);
    }

    public function operation(Operation $op, string $route): void
    {
        (new DossierWorkflowDocument)->operation($op, $route);
        $op->description = 'Phase 3: Sanctum Bearer with api ability, active account and explicit active facility dossiers.view. All responses private, no-store. Writes use separate facility permissions, UUID request_id, optimistic visit and row lock_version, transactional audit, explicit void reason and omission-preserves-history. Completed visits are read-only. Clinic must be selected before a doctor whose assignment covers actual visit_date. Prescriptions never create dispensing rows. Medication names/codes are immutable item snapshots. No reporting periods are created or required. See patient-dossiers-phase-three.md for the full contract.';
        $i = fn () => new IntegerType;
        $s = fn () => new StringType;
        $null = fn () => (new StringType)->nullable(true);
        $fields = ['facility_id' => $i(), 'request_id' => $s()->format('uuid'), 'lock_version' => $i()];
        $required = array_keys($fields);
        $row = ['id' => $i(), 'lock_version' => $i(), 'remove' => new BooleanType, 'void_reason' => $null(), 'note' => $null()];
        if (str_ends_with($route, '/clinical')) {
            $event = $this->object($row + ['catalog_id' => $i(), 'clinic_id' => $i(), 'doctor_id' => $i()]);
            $event->required = ['catalog_id', 'clinic_id', 'doctor_id'];
            $fields += ['services' => $this->list($event), 'procedures' => $this->list($event)];
            $required = [...$required, 'services', 'procedures'];
            $op->description .= ' Requires dossiers.clinical.update. performed_on is the visit date; correction propagates atomically to dossier-managed events.';
        } elseif ($route === 'dossiers/medications') {
            $fields = ['facility_id' => $i(), 'request_id' => $s()->format('uuid'), 'code' => $s(), 'name_ar' => $s()];
            $required = array_keys($fields);
            $op->description .= ' Explicit global medications.create required; never a side effect of prescription save. Normalized duplicate code or name returns 422.';
        } elseif (str_ends_with($route, '/medications')) {
            $item = $this->object($row + ['medication_id' => $i(), 'display_order' => $i()]);
            $item->required = ['medication_id', 'display_order'];
            $rx = $this->object($row + ['prescribing_clinic_id' => $i(), 'prescribing_staff_id' => $i(), 'prescribed_on' => $s()->format('date'), 'items' => $this->list($item)])->nullable(true);
            $rx->required = ['prescribing_clinic_id', 'prescribing_staff_id', 'prescribed_on', 'items'];
            $out = $this->object($row + ['code' => $s()->enum(array_keys(DossierOutcomeSeeder::OUTCOMES)), 'clinic_id' => $i(), 'doctor_id' => $i(), 'outcome_on' => $s()->format('date'), 'referral_target' => $null(), 'outgoing_referral_date' => $null(), 'outgoing_referral_reason' => $null()])->nullable(true);
            $out->required = ['code', 'clinic_id', 'doctor_id', 'outcome_on'];
            $fields += ['prescription' => $rx, 'outcome' => $out];
            $required = [...$required, 'prescription', 'outcome'];
            $op->description .= ' Requires dossiers.clinical.update. One active prescription header with multiple items, zero prescriptions allowed. One current outcome. Dates must not be future. DOS-REFER requires all three outgoing fields; other outcomes prohibit them. Incoming referral stays independent.';
        } elseif (str_ends_with($route, '/complete')) {
            $fields += ['dossier_lock_version' => $i(), 'confirmed' => new BooleanType, 'clinic_id' => $i(), 'attending_staff_id' => $i()];
            $required = array_keys($fields);
            $op->description .= ' Requires dossiers.visits.complete; initial activation additionally dossiers.finalize. Six saved sections, diagnosis, outcome, valid retained contexts, no pending uploads, explicit confirmation. Atomically activates initial dossier and completes only this visit.';
        } elseif (str_ends_with($route, '/review')) {
            $fields['confirmed'] = new BooleanType;
            $required[] = 'confirmed';
            $op->description .= ' Requires dossiers.visits.update or dossiers.visits.complete. Explicitly confirms optional empty sections; rejects pending uploads.';
        } elseif (str_ends_with($route, '/uploads')) {
            $fields += ['title' => $s(), 'original_filename' => $s()];
            $required = array_keys($fields);
            $op->description .= ' Requires dossiers.attachments.upload. Reserves one upload for one hour. Returns data.upload_id (201).';
            $op->responses = [];
            $op->addResponse(Response::make(201)->setDescription('Upload reserved')->setContent('application/json', Schema::fromType($this->object(['data' => $this->object(['upload_id' => $i()])]))));
        } elseif (str_ends_with($route, '/uploads/{upload}')) {
            $op->requestBodyObject = (new RequestBodyObject)->setContent('multipart/form-data', Schema::fromType($this->object(['file' => $s()->format('binary')])));
            $op->description .= ' Requires dossiers.attachments.upload. facility_id query required. PDF/XLS/XLSX/JPEG/PNG/WebP only, detected content MIME, single extension, max DOSSIER_ATTACHMENT_MAX_KB (default 10240). Returns data.attachment_id (201). Replay requires identical hash and size.';
            $op->responses = [];
            $op->addResponse(Response::make(201)->setDescription('File saved')->setContent('application/json', Schema::fromType($this->object(['data' => $this->object(['attachment_id' => $i()])]))));

        } elseif (str_ends_with($route, '/cancel')) {
            $fields = ['facility_id' => $i()];
            $required = ['facility_id'];
            $op->description .= ' Cancel only the current actor’s pending reservation; 204, no file is destroyed.';
            $op->responses = [];
            $op->addResponse(Response::make(204)->setDescription('Reservation cancelled'));
        } elseif (str_ends_with($route, '/void')) {
            $fields += ['attachment_lock_version' => $i(), 'void_reason' => $s()];
            $required = array_keys($fields);
            $op->description .= ' Requires dossiers.attachments.void; retains the private binary and audit history.';
        } elseif (str_contains($route, '/report/') || str_contains($route, '/export/')) {
            $fields = ['facility_id' => $i(), 'columns' => $this->list($s()->enum(array_keys(DossierReports::COLUMNS)))];
            foreach (['search', 'status', 'oncology', 'visits', 'from', 'to', 'sort', 'direction'] as $key) {
                $fields[$key] = $s();
            }$required = ['facility_id'];
            $op->description .= ' POST requires dossiers.export. Authoritative complete filtered results, not visible page; safe limits 1000 dossiers / 5000 detail rows. Drafts marked incomplete. Private attachment metadata requires attachments.view; no binary embedding. Returns PDF or XLSX attachment; report generation assigns an audited report number. Individual dossier report is Full dossier history: includes saved and explicitly voided linked visits/facts and reasons, with current saved versions; prior correction values stay in separately authorized audit. Optional from/to select actual visit_date inclusively (never created_at); each selected fact retains its own date. Current personal/medical data are labelled current. List and individual-visit semantics stay unchanged.';
            $op->responses = [];
            $report = Response::make(200)->setDescription('Private report');
            foreach (['application/pdf', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] as $mime) {
                $report->setContent($mime, Schema::fromType($s()->format('binary')));
            }
            $op->addResponse($report);
        } elseif (str_ends_with($route, '/download')) {
            $op->description .= ' Requires dossiers.attachments.download. Rechecks full dossier/visit/patient/facility scope, streams a private file with Content-Disposition attachment and X-Content-Type-Options nosniff. No storage key or Bearer URL.';
            $op->responses = [];
            $op->addResponse(Response::make(200)->setDescription('Private attachment')->setContent('application/octet-stream', Schema::fromType($s()->format('binary'))));
        } elseif (str_ends_with($route, '/attachments')) {
            $op->description .= ' Requires dossiers.attachments.view. Server page/per_page, visit_id, visit-date from/to, extension and direction. data contains title, original_filename, MIME, size, sha256, actor/upload time, visit code/date and version; never storage_key.';
            $op->responses = [];
            $op->addResponse(Response::make(200)->setDescription('Paginated scoped metadata')->setContent('application/json', Schema::fromType($this->object(['data' => $this->list($this->object(['id' => $i(), 'visit_id' => $i(), 'title' => $s(), 'original_filename' => $s(), 'mime_type' => $s(), 'extension' => $s(), 'size' => $i(), 'sha256' => $s(), 'uploaded_by' => $i(), 'uploaded_at' => $s(), 'lock_version' => $i(), 'visit_no' => $s(), 'visit_date' => $s()])), 'meta' => $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], $i()))]))));
        } else {
            $op->description .= ' Subsequent visits require an active dossier and dossiers.visits.create. Reuse the visit/diagnosis request and selected-visit progress contract, independent initial/subsequent identity; GET visits/new creates nothing.';

            return;
        }
        if ($op->method !== 'get' && ! str_ends_with($route, '/uploads/{upload}')) {
            $schema = $this->object($fields);
            $schema->required = $required;
            $op->requestBodyObject = (new RequestBodyObject)->setContent('application/json', Schema::fromType($schema));
        }
        foreach ([401, 403, 404, 409, 422, 500] as $code) {
            $op->addResponse(Response::make($code)->setDescription($code === 500 ? 'DOSSIERS_UNAVAILABLE; no internals' : 'Sanitized authorization, scope, conflict or field-validation error'));
        }
    }
}
