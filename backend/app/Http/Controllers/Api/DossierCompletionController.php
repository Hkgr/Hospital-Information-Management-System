<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dossiers\DossierReportRequest;
use App\Http\Requests\Dossiers\SaveDossierSection;
use App\Http\Requests\Dossiers\SaveVisitClinical;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierAttachments;
use App\Services\Dossiers\DossierClinicalWriter;
use App\Services\Dossiers\DossierFinalizer;
use App\Services\Dossiers\DossierReports;
use App\Services\Dossiers\DossierVisitWriter;
use App\Services\Dossiers\DossierWizardQueries;
use App\Services\Dossiers\DossierWrites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DossierCompletionController extends Controller
{
    public function report(DossierReportRequest $r)
    {
        $f = $this->scope($r, 'export');

        return app(DossierReports::class)->export($r, $f, $r->validated(), $r->route('format'), $r->route('dossier') ? (int) $r->route('dossier') : null, $r->route('visit') ? (int) $r->route('visit') : null);
    }

    private function scope(Request $r, string $action = 'view'): array
    {
        $r->validate(['facility_id' => ['required', 'integer', 'min:1']]);

        return app(DossierAccess::class)->facility($r->user(), $r->integer('facility_id'), $action);
    }

    private function snapshot(array $f, int $dossier, int $visit, int $status = 200)
    {
        return response()->json(['data' => app(DossierWizardQueries::class)->snapshot($f, $dossier, $visit)], $status);
    }

    public function progress(Request $r, int $dossier, int $visit)
    {
        return $this->snapshot($this->scope($r), $dossier, $visit);
    }

    public function newVisit(Request $r, int $dossier)
    {
        $f = $this->scope($r, 'visits.create');
        $d = app(DossierWrites::class)->dossier($f, $dossier, false);
        abort_unless($d['status'] === 'active', 409);

        return response()->json(['data' => app(DossierWizardQueries::class)->snapshot($f, $dossier, null, true)]);
    }

    public function subsequent(SaveDossierSection $r, int $dossier)
    {
        $f = $this->scope($r, 'visits.create');
        $id = app(DossierVisitWriter::class)->save($r, $f, $dossier, $r->validated(), null, true);

        return $this->snapshot($f, $dossier, $id, 201);
    }

    public function clinical(SaveVisitClinical $r, int $dossier, int $visit)
    {
        $f = $this->scope($r, 'clinical.update');
        app(DossierClinicalWriter::class)->save($r, $f, $dossier, $visit, $r->route('section'), $r->validated());

        return $this->snapshot($f, $dossier, $visit);
    }

    public function review(Request $r, int $dossier, int $visit)
    {
        $f = $this->scope($r);
        $data = $r->validate(['facility_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']]);
        app(DossierFinalizer::class)->save($r, $f, $dossier, $visit, $data, false);

        return $this->snapshot($f, $dossier, $visit);
    }

    public function complete(Request $r, int $dossier, int $visit)
    {
        $f = $this->scope($r, 'visits.complete');
        $data = $r->validate(['facility_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:1'], 'dossier_lock_version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted'], 'clinic_id' => ['required', 'integer', 'min:1'], 'attending_staff_id' => ['required', 'integer', 'min:1']]);
        app(DossierFinalizer::class)->save($r, $f, $dossier, $visit, $data, true);

        return $this->snapshot($f, $dossier, $visit);
    }

    public function beginUpload(Request $r, int $dossier, int $visit)
    {
        $f = $this->scope($r, 'attachments.upload');
        $data = $r->validate(['facility_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:200'], 'original_filename' => ['required', 'string', 'max:200']]);
        $id = app(DossierAttachments::class)->begin($r, $f, $dossier, $visit, $data);

        return response()->json(['data' => ['upload_id' => $id]], 201);
    }

    public function finishUpload(Request $r, int $dossier, int $visit, int $upload)
    {
        $f = $this->scope($r, 'attachments.upload');
        $r->validate(['file' => ['required', 'file', 'max:'.config('dossiers.attachment_max_kb')]]);
        $id = app(DossierAttachments::class)->finish($r, $f, $dossier, $visit, $upload, $r->file('file'));

        return response()->json(['data' => ['attachment_id' => $id]], 201);
    }

    public function cancelUpload(Request $r, int $dossier, int $visit, int $upload)
    {
        $f = $this->scope($r, 'attachments.upload');
        app(DossierAttachments::class)->cancel($r, $f, $dossier, $visit, $upload);

        return response()->noContent();
    }

    public function attachments(Request $r, int $dossier)
    {
        $f = $this->scope($r, 'attachments.view');
        $filters = $r->validate(['visit_id' => ['sometimes', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'direction' => ['sometimes', 'in:asc,desc'], 'extension' => ['nullable', 'in:pdf,xls,xlsx,jpg,jpeg,png,webp'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'in:10,20,50,100']]);

        return response()->json(app(DossierAttachments::class)->listing($f, $dossier, $filters));
    }

    public function download(Request $r, int $dossier, int $visit, int $attachment)
    {
        $f = $this->scope($r, 'attachments.download');

        return app(DossierAttachments::class)->download($f, $dossier, $visit, $attachment);
    }

    public function voidAttachment(Request $r, int $dossier, int $visit, int $attachment)
    {
        $f = $this->scope($r, 'attachments.void');
        $data = $r->validate(['facility_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid'], 'lock_version' => ['required', 'integer', 'min:1'], 'attachment_lock_version' => ['required', 'integer', 'min:1'], 'void_reason' => ['required', 'string', 'max:255']]);
        app(DossierAttachments::class)->void($r, $f, $dossier, $visit, $attachment, $data);

        return $this->snapshot($f, $dossier, $visit);
    }

    public function medication(Request $r)
    {
        $f = $this->scope($r);
        app(DossierAccess::class)->global($r->user(), 'medications.create');
        $data = $r->validate(['facility_id' => ['required', 'integer'], 'request_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:50'], 'name_ar' => ['required', 'string', 'max:200']]);
        $normalize = fn ($s) => trim(preg_replace('/\s+/u', ' ', $s));
        $code = $normalize($data['code']);
        $name = $normalize($data['name_ar']);
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages(['name_ar' => 'أدخل اسمًا وكودًا غير فارغين.']);
        }
        $id = app(DossierWrites::class)->once($r, $f, $data, 'medication:new', function () use ($r, $f, $code, $name) {
            $key = ['sequence_key' => 'medication_directory', 'scope_key' => 'global', 'period_key' => 'all'];
            DB::table('number_sequences')->insertOrIgnore($key);
            DB::table('number_sequences')->where($key)->lockForUpdate()->first();
            foreach (['code' => $code, 'name_ar' => $name] as $field => $value) {
                if (DB::table('medications')->whereRaw("TRIM(REGEXP_REPLACE($field, '[[:space:]]+', ' ')) = ?", [$value])->exists()) {
                    throw ValidationException::withMessages([$field => 'القيمة موجودة في دليل الأدوية؛ اختر التعريف الموجود.']);
                }
            }
            $id = DB::table('medications')->insertGetId(['code' => $code, 'name_ar' => $name, 'lock_version' => 1, 'archived_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            app(DossierWrites::class)->audit($r, $f, 'medication', $id, null, ['code' => $code, 'name_ar' => $name]);

            return $id;
        });

        return response()->json(['data' => DB::table('medications')->where('id', $id)->first(['id', 'code', 'name_ar'])], 201);
    }
}
