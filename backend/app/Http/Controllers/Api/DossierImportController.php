<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\Imports\ImportBatches;
use App\Services\Dossiers\Imports\ImportWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DossierImportController extends Controller
{
    public function __construct(private DossierAccess $access, private ImportBatches $batches) {}

    private function scope(Request $r, string $action): array
    {
        $r->validate(['facility_id' => 'required|integer|min:1', 'page' => 'sometimes|integer|min:1', 'status' => 'nullable|in:pending,valid,needs_review,error,committed,skipped', 'action' => 'nullable|in:new_patient,new_dossier,reuse_dossier,new_fact,skip']);
        $f = $this->access->facility($r->user(), $r->integer('facility_id'), 'import.view');
        $this->access->facility($r->user(), $f['id'], 'import.'.$action);

        return $f;
    }

    private function metadata(Request $r): void
    {
        $r->validate(['purpose' => 'required|in:legacy_migration,offline_capture', 'cutover_date' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01']);
    }

    public function index(Request $r)
    {
        $f = $this->scope($r, 'view');

        return response()->json(['data' => DB::table('dossier_import_batches')->where('facility_id', $f['id'])->orderByDesc('id')->paginate(25, ['id', 'status', 'purpose', 'cutover_date', 'total_rows', 'lock_version', 'created_at'])]);
    }

    public function show(Request $r, int $batch)
    {
        $f = $this->scope($r, 'view');

        return response()->json(['data' => $this->batches->present($f, $batch, $r->integer('page', 1), $r->input('status'), $r->input('action'))]);
    }

    public function upload(Request $r)
    {
        $f = $this->scope($r, 'create');
        $this->metadata($r);
        $r->validate(['file' => 'required|file|extensions:xlsx|max:10240']);
        $id = $this->batches->upload($r, $f, $r->file('file'), $r->string('purpose')->toString(), $r->string('cutover_date')->toString());

        return response()->json(['data' => $this->batches->present($f, $id)], 201);
    }

    public function step(Request $r, int $batch)
    {
        $operation = $r->route('operation');
        $f = $this->scope($r, $operation);
        $r->validate(['lock_version' => 'required|integer|min:1', 'confirm' => 'sometimes|boolean']);
        if ($operation === 'commit') {
            $r->validate(['confirm' => 'accepted']);
        }
        $this->batches->step($r, $f, $batch, $r->integer('lock_version'), $operation);

        return response()->json(['data' => $this->batches->present($f, $batch)]);
    }

    public function template(Request $r)
    {
        $f = $this->scope($r, 'download');
        $this->metadata($r);
        $refs = [];
        foreach (['diagnoses', 'services', 'procedures', 'medications', 'visit_results'] as $table) {
            $q = DB::table($table)->where('is_active', true);
            if (in_array($table, ['services', 'procedures'])) {
                $q->whereNull('archived_at');
            }
            foreach ($q->orderBy('id')->get(['id', 'code', 'name_ar']) as $row) {
                $refs[] = [$table, $row->id, $row->code, $row->name_ar, ''];
            }
        }
        foreach (DB::table('clinics')->where('facility_id', $f['id'])->where('is_active', true)->whereNull('archived_at')->orderBy('id')->get(['id', 'code', 'name_ar']) as $row) {
            $refs[] = ['clinics', $row->id, $row->code, $row->name_ar, ''];
        }
        foreach (DB::table('clinic_staff as cs')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')->join('staff as s', 's.id', '=', 'cs.staff_id')->where('c.facility_id', $f['id'])->where('c.is_active', true)->whereNull('c.archived_at')->where('s.is_active', true)->select('s.id', 's.staff_code', 's.full_name', 'cs.clinic_id', 'cs.starts_on', 'cs.ends_on')->orderBy('s.id')->get() as $row) {
            $refs[] = ['doctors', $row->id, $row->staff_code, $row->full_name, 'clinic='.$row->clinic_id.'; '.$row->starts_on.' / '.($row->ends_on ?? '')];
        }
        foreach (DB::table('governorates')->where('country_code', 'SY')->orderBy('id')->get() as $g) {
            $refs[] = ['governorates', $g->id, $g->code, $g->name_ar, ''];
        }
        foreach (DB::table('cities as c')->join('governorates as g', 'g.id', '=', 'c.governorate_id')->where('g.country_code', 'SY')->orderBy('c.id')->get(['c.id', 'c.name_ar', 'c.governorate_id']) as $city) {
            $refs[] = ['cities', $city->id, '', $city->name_ar, 'governorate='.$city->governorate_id];
        }
        $book = app(ImportWorkbook::class)->template($f, $r->string('purpose')->toString(), $r->string('cutover_date')->toString(), $refs);

        return $this->download($book, 'patient-import-template.xlsx');
    }

    public function errors(Request $r, int $batch)
    {
        $f = $this->scope($r, 'download');
        $this->batches->batch($f, $batch);
        $book = new Spreadsheet;
        $book->getDefaultStyle()->getFont()->setName('Cairo');
        $sheet = $book->getActiveSheet()->setTitle('Errors')->setRightToLeft(true);
        $sheet->fromArray([['الورقة', 'السطر', 'معرّف المصدر', 'الحقل', 'الخطأ', 'الإجراء']]);
        $n = 1;
        foreach (DB::table('dossier_import_rows')->where('batch_id', $batch)->where('facility_id', $f['id'])->whereIn('status', ['error', 'needs_review'])->orderBy('id')->cursor() as $row) {
            foreach (json_decode($row->errors ?? '{}', true) as $field => $messages) {
                foreach ($messages as $message) {
                    $n++;
                    foreach ([$row->sheet, $row->row_number, $row->source_record_id, $field, $message, 'راجع المصدر والهوية ثم أعد الرفع؛ لا تغيّر معرّف مصدر سبق اعتماده.'] as $i => $value) {
                        $sheet->setCellValueExplicit([$i + 1, $n], $i === 1 ? (int) $value : (string) $value, $i === 1 ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    }
                }
            }
        }
        $sheet->freezePane('A2')->setAutoFilter('A1:F'.$n);
        foreach (['A' => 20, 'B' => 10, 'C' => 28, 'D' => 24, 'E' => 65, 'F' => 60] as $c => $w) {
            $sheet->getColumnDimension($c)->setWidth($w);
        }
        $sheet->getStyle('A1:F'.$n)->getAlignment()->setWrapText(true);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:F'.$n)->setRowsToRepeatAtTopByStartAndEnd(1, 1);

        return $this->download($book, 'import-errors.xlsx');
    }

    private function download(Spreadsheet $book, string $name)
    {
        $response = response()->streamDownload(function () use ($book) {
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
        // Match the existing shared browser downloader's quoted ASCII filename contract.
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$name.'"');

        return $response;
    }
}
