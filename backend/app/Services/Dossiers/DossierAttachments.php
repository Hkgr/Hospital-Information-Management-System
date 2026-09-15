<?php

namespace App\Services\Dossiers;

use App\Services\Catalog\CatalogQueries;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DossierAttachments
{
    public const PUBLIC_FIELDS = ['a.id', 'a.visit_id', 'a.title', 'a.original_filename', 'a.mime_type', 'a.extension', 'a.size', 'a.sha256', 'a.entered_by as uploaded_by', 'a.created_at as uploaded_at', 'a.lock_version', 'v.visit_no', 'v.visit_date'];

    public function listing(array $f, int $dossier, array $filters): array
    {
        app(DossierWrites::class)->dossier($f, $dossier, false);
        $q = DB::table('visit_attachments as a')->join('visits as v', fn ($j) => $j->on('v.id', '=', 'a.visit_id')->on('v.dossier_id', '=', 'a.dossier_id')->on('v.facility_id', '=', 'a.facility_id'))
            ->where('a.facility_id', $f['id'])->where('a.dossier_id', $dossier)->whereNull('a.voided_at')->whereNull('v.voided_at');
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (! empty($filters[$key])) {
                $q->where('v.visit_date', $op, $filters[$key]);
            }
        }
        if (! empty($filters['visit_id'])) {
            $q->where('a.visit_id', $filters['visit_id']);
        }
        if (! empty($filters['extension'])) {
            $q->where('a.extension', $filters['extension']);
        }
        $p = $q->orderBy('v.visit_date', $filters['direction'] ?? 'desc')->orderByDesc('a.id')->paginate($filters['per_page'] ?? 20, self::PUBLIC_FIELDS, 'page', $filters['page'] ?? 1);

        return ['data' => $p->items(), 'meta' => CatalogQueries::meta($p)];
    }

    private function filename(string $name): string
    {
        if (! preg_match('/\A[^.\\\\\/\x00-\x1f]+\.(pdf|xls|xlsx|jpg|jpeg|png|webp)\z/ui', $name, $m)) {
            throw ValidationException::withMessages(['file' => 'اسم الملف أو امتداده غير مقبول؛ استخدم اسمًا واضحًا وامتدادًا واحدًا معتمدًا.']);
        }

        return strtolower($m[1]);
    }

    public function begin(Request $r, array $f, int $dossier, int $visit, array $data): int
    {
        $this->filename($data['original_filename']);
        $writes = app(DossierWrites::class);

        return $writes->once($r, $f, $data, "attachment:begin:$dossier:$visit", function () use ($r, $f, $dossier, $visit, $data, $writes) {
            $v = app(DossierClinicalWriter::class)->visit($f, $dossier, $visit);
            DossierWrites::version((array) $v, $data['lock_version']);
            $id = DB::table('visit_attachment_uploads')->insertGetId(['facility_id' => $f['id'], 'dossier_id' => $dossier, 'visit_id' => $visit, 'uploaded_by' => $r->user()->id, 'request_id' => $data['request_id'], 'title' => $data['title'], 'original_filename' => $data['original_filename'], 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
            $writes->audit($r, $f, 'dossier_upload', $id, null, ['visit_id' => $visit, 'state' => 'pending'], 'started');

            return $id;
        });
    }

    private function ticket(Request $r, array $f, int $dossier, int $visit, int $id, bool $lock = false): object
    {
        $q = DB::table('visit_attachment_uploads')->where('id', $id)->where('facility_id', $f['id'])->where('dossier_id', $dossier)->where('visit_id', $visit)->where('uploaded_by', $r->user()->id);
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function inspect(UploadedFile $file, string $filename): array
    {
        $ext = $this->filename($filename);
        if (! $file->isValid() || $file->getClientOriginalName() !== $filename || $file->getSize() <= 0 || $file->getSize() > config('dossiers.attachment_max_kb') * 1024) {
            throw ValidationException::withMessages(['file' => 'الملف غير صالح أو يتجاوز الحجم المسموح.']);
        }
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $expected = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        if (isset($expected[$ext])) {
            if ($mime !== $expected[$ext] || ($ext !== 'pdf' && @getimagesize($path) === false)) {
                throw ValidationException::withMessages(['file' => 'محتوى الملف لا يطابق امتداده.']);
            }
        } else {
            if ($ext === 'xlsx') {
                $zip = new \ZipArchive;
                if ($zip->open($path) !== true) {
                    throw ValidationException::withMessages(['file' => 'مصنف Excel غير صالح.']);
                }
                try {
                    $expanded = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = $zip->statIndex($i);
                        $expanded += $entry['size'];
                        if ($expanded > 100 * 1024 * 1024 || preg_match('#(?:^|/)(?:\.\.|vbaProject\.bin)(?:/|$)#i', $entry['name'])) {
                            throw ValidationException::withMessages(['file' => 'المصنف يحتوي محتوى غير مسموح أو حجمًا داخليًا مفرطًا.']);
                        }
                    }
                } finally {
                    $zip->close();
                }
            }
            try {
                $reader = IOFactory::identify($path, [$ext === 'xls' ? 'Xls' : 'Xlsx']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['file' => 'محتوى الملف ليس مصنف Excel مطابقًا للامتداد.']);
            }
            $mime = $reader === 'Xls' ? 'application/vnd.ms-excel' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        return ['extension' => $ext, 'mime_type' => $mime, 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $path)];
    }

    public function finish(Request $r, array $f, int $dossier, int $visit, int $upload, UploadedFile $file): int
    {
        $ticket = $this->ticket($r, $f, $dossier, $visit, $upload);
        $info = $this->inspect($file, $ticket->original_filename);
        if ($ticket->attachment_id) {
            return $this->replay($ticket, $info);
        }
        $disk = Storage::disk(config('dossiers.attachment_disk'));
        $key = 'files/'.Str::uuid().'.'.$info['extension'];
        $temporary = 'staging/'.Str::uuid();
        $disk->putFileAs('staging', $file, basename($temporary));
        $committed = false;
        try {
            $id = DB::transaction(function () use ($r, $f, $dossier, $visit, $upload, $info, $key, $temporary, $disk) {
                $v = app(DossierClinicalWriter::class)->visit($f, $dossier, $visit);
                $ticket = $this->ticket($r, $f, $dossier, $visit, $upload, true);
                if ($ticket->attachment_id) {
                    return $this->replay($ticket, $info);
                }
                if ($ticket->state !== 'pending' || $ticket->expires_at < now()->toDateTimeString()) {
                    DossierWrites::conflict('انتهت محاولة الرفع أو أُلغيت؛ ابدأ محاولة جديدة لهذا الملف.');
                }
                $disk->move($temporary, $key);
                $id = DB::table('visit_attachments')->insertGetId($info + ['facility_id' => $f['id'], 'dossier_id' => $dossier, 'visit_id' => $visit, 'title' => $ticket->title, 'original_filename' => $ticket->original_filename, 'storage_key' => $key, 'client_request_id' => $ticket->request_id, 'entered_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('visit_attachment_uploads')->where('id', $upload)->update(['state' => 'complete', 'attachment_id' => $id, 'updated_at' => now()]);
                DB::table('visits')->where('id', $visit)->update(['phase_three' => true, 'lock_version' => $v->lock_version + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()]);
                $writes = app(DossierWrites::class);
                $writes->progress($r, $f, $dossier, 'attachments', 'needs_review', $visit);
                $writes->audit($r, $f, 'visit_attachment', $id, null, $info + ['title' => $ticket->title, 'visit_id' => $visit], 'uploaded');

                return $id;
            });
            $committed = true;

            return $id;
        } finally {
            $disk->delete($temporary);
            if (! $committed) {
                $disk->delete($key);
            }
        }
    }

    private function replay(object $ticket, array $info): int
    {
        $row = DB::table('visit_attachments')->where('id', $ticket->attachment_id)->first();
        if (! $row || ! hash_equals($row->sha256, $info['sha256']) || $row->size != $info['size']) {
            DossierWrites::conflict('استُخدم معرّف الرفع لملف مختلف.');
        }

        return $row->id;
    }

    public function cancel(Request $r, array $f, int $dossier, int $visit, int $upload): void
    {
        DB::transaction(function () use ($r, $f, $dossier, $visit, $upload) {
            app(DossierClinicalWriter::class)->visit($f, $dossier, $visit);
            $t = $this->ticket($r, $f, $dossier, $visit, $upload, true);
            if ($t->state === 'pending') {
                DB::table('visit_attachment_uploads')->where('id', $upload)->update(['state' => 'cancelled', 'updated_at' => now()]);
                app(DossierWrites::class)->audit($r, $f, 'dossier_upload', $upload, (array) $t, ['state' => 'cancelled'], 'cancelled');
            }
        });
    }

    public function download(array $f, int $dossier, int $visit, int $id)
    {
        $d = app(DossierWrites::class)->dossier($f, $dossier, false);
        abort_unless(DB::table('visits')->where('id', $visit)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->where('patient_id', $d['patient_id'])->whereNull('voided_at')->exists(), 404);
        $a = DB::table('visit_attachments')->where('id', $id)->where('visit_id', $visit)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->whereNull('voided_at')->first();
        abort_unless($a, 404);

        return Storage::disk(config('dossiers.attachment_disk'))->download($a->storage_key, $a->original_filename, ['Content-Type' => $a->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function void(Request $r, array $f, int $dossier, int $visit, int $id, array $data): int
    {
        $writes = app(DossierWrites::class);

        return $writes->once($r, $f, $data, "attachment:void:$id", function () use ($r, $f, $dossier, $visit, $id, $data, $writes) {
            $v = app(DossierClinicalWriter::class)->visit($f, $dossier, $visit);
            DossierWrites::version((array) $v, $data['lock_version']);
            $old = DB::table('visit_attachments')->where('id', $id)->where('visit_id', $visit)->where('dossier_id', $dossier)->where('facility_id', $f['id'])->whereNull('voided_at')->lockForUpdate()->first();
            abort_unless($old, 404);
            DossierWrites::version((array) $old, $data['attachment_lock_version']);
            $fields = ['voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $data['void_reason'], 'lock_version' => $old->lock_version + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()];
            DB::table('visit_attachments')->where('id', $id)->update($fields);
            DB::table('visits')->where('id', $visit)->update(['lock_version' => $v->lock_version + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()]);
            $writes->progress($r, $f, $dossier, 'attachments', 'needs_review', $visit);
            $writes->audit($r, $f, 'visit_attachment', $id, (array) $old, $fields, 'voided');

            return $id;
        });
    }
}
