<?php

namespace App\OpenApi;

use App\Services\Dossiers\Imports\ImportWorkbook;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class DossierImportDocument extends ClinicDocumentTransformer
{
    public function operation(Operation $op, string $route): void
    {
        $op->tags = ['Patient Card imports'];
        $op->description = 'Controlled XLSX '.ImportWorkbook::VERSION.'. Requires active-account Sanctum Bearer api ability, facility_id, dossiers.view and dossiers.import.view plus create/validate/commit/download/cancel for the operation. Clinical commit additionally requires the normal global patient and facility dossier/visit/clinical permissions. No automatic grants. Template metadata purpose=legacy_migration|offline_capture and cutover_date must match upload exactly. Maximum 10 MiB compressed, 80 MB expanded, 5000 patients and 30000 data rows. Reject macros, external XML/entities/links, formulas, invalid archives and unknown template versions. Upload and validation write no clinical facts. Validation and commit are resumable versioned HTTP steps (at most ten patient aggregates each); call explicitly with latest lock_version. Only validated bundles commit, atomically per patient; recheck identity, versions and active date-eligible directory references. No demographic auto-merge, canonical-code replacement, silent overwrite, inferred visits or visit completion. Source IDs are facility/sheet scoped: unchanged replays skip, changed payloads require review. Exact file hash reuses the existing batch. Same-day visits with distinct source IDs remain distinct. Unmatched sources remain review rows and do not commit. Private no-store; responses never return file paths, hashes or encrypted payloads. Error workbook contains row references and fixed errors, not raw source values. Original private workbook expires after 30 days; committed encrypted source provenance remains retained. No pathology/oncology/dispensing/attachments import.';
        $i = fn () => new IntegerType;
        $s = fn () => new StringType;
        $row = $this->object(['id' => $i(), 'sheet' => $s(), 'row_number' => $i(), 'source_record_id' => $s(), 'local_patient_ref' => $s(), 'local_visit_ref' => $s()->nullable(true), 'status' => $s(), 'action' => $s()->nullable(true), 'errors' => new ObjectType, 'match' => new ObjectType, 'dossier_id' => $i()->nullable(true), 'visit_id' => $i()->nullable(true)]);
        $batch = $this->object(['id' => $i(), 'facility_id' => $i(), 'template_version' => $s(), 'purpose' => $s()->enum(['legacy_migration', 'offline_capture']), 'cutover_date' => $s()->format('date'), 'status' => $s()->enum(['uploaded', 'validating', 'needs_review', 'validated', 'committing', 'completed', 'completed_with_errors', 'failed', 'cancelled']), 'lock_version' => $i(), 'total_rows' => $i(), 'counts' => new ObjectType, 'actions' => new ObjectType, 'sheets' => new ObjectType, 'matches' => new ObjectType, 'pre_cutover_patients' => $i(), 'created_at' => $s(), 'file_expires_at' => $s(), 'rows' => $this->object(['data' => $this->list($row), 'current_page' => $i(), 'last_page' => $i(), 'total' => $i()])]);
        $op->responses = [];
        if (str_ends_with($route, '.xlsx')) {
            $op->addResponse(Response::make(200)->setDescription('Authorized XLSX download')->setContent('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', Schema::fromType($s()->format('binary'))));
        } else {
            $index = $route === 'dossiers/imports' && $op->method === 'get';
            $body = $index ? $this->object(['data' => $this->object(['data' => $this->list($this->object(['id' => $i(), 'status' => $s(), 'purpose' => $s(), 'cutover_date' => $s()->format('date'), 'total_rows' => $i(), 'lock_version' => $i(), 'created_at' => $s()])), 'current_page' => $i(), 'last_page' => $i(), 'total' => $i()])]) : $this->object(['data' => $batch]);
            $op->addResponse(Response::make($route === 'dossiers/imports' && $op->method === 'post' ? 201 : 200)->setDescription('Scoped import response')->setContent('application/json', Schema::fromType($body)));
        }
        if ($op->method === 'post') {
            $upload = $route === 'dossiers/imports';
            $fields = ['facility_id' => $i()];
            if ($upload) {
                $fields += ['file' => $s()->format('binary'), 'purpose' => $s()->enum(['legacy_migration', 'offline_capture']), 'cutover_date' => $s()->format('date')];
            } else {
                $fields += ['lock_version' => $i()];
                if (str_ends_with($route, '/commit')) {
                    $fields['confirm'] = new BooleanType;
                }
            }
            $op->requestBodyObject = (new RequestBodyObject)->required()->setContent($upload ? 'multipart/form-data' : 'application/json', Schema::fromType($this->object($fields)));
        }
        foreach ([401 => 'Unauthenticated', 403 => 'Missing import or clinical permission / facility inactive', 404 => 'Batch outside authorized facility', 409 => 'Stale lock_version or invalid state; fetch latest batch', 413 => 'Workbook exceeds upload bound', 422 => 'Invalid template, input or archive', 500 => 'Safe internal failure; no source/SQL details'] as $code => $description) {
            $op->addResponse(Response::make($code)->setDescription($description));
        }
    }
}
