<?php

namespace App\Services\Dossiers\Imports;

use App\Services\Dossiers\DossierClinicalContext;
use App\Services\Dossiers\DossierWrites;
use App\Services\Dossiers\PatientCardCodes;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportBatches
{
    public const CHUNK = 10;

    public function upload(Request $r, array $f, UploadedFile $file, string $purpose, string $cutover): int
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $old = DB::table('dossier_import_batches')->where('facility_id', $f['id'])->where('file_hash', $hash)->first();
        if ($old) {
            if ($old->purpose !== $purpose || $old->cutover_date !== $cutover) {
                DossierWrites::conflict('سبق رفع الملف ببيانات دفعة مختلفة؛ افتح الدفعة الأصلية.');
            }

            return (int) $old->id;
        }
        $rows = app(ImportWorkbook::class)->read($file->getRealPath(), $f['id'], $purpose, $cutover);
        $patients = [];
        $visits = [];
        foreach ($rows as $row) {
            $d = $row['data'];
            if ($row['sheet'] === 'Patients') {
                $this->reference($d['local_patient_ref']);
                if (isset($patients[$d['local_patient_ref']])) {
                    throw ValidationException::withMessages(['file' => 'مرجع المريض المحلي مكرر.']);
                }
                $patients[$d['local_patient_ref']] = true;
            }
            if ($row['sheet'] === 'Visits') {
                $this->reference($d['local_visit_ref']);
                if (isset($visits[$d['local_visit_ref']])) {
                    throw ValidationException::withMessages(['file' => 'مرجع الزيارة المحلي مكرر.']);
                }
                $visits[$d['local_visit_ref']] = $d['local_patient_ref'];
            }
        }
        foreach ($rows as &$row) {
            $d = $row['data'];
            $ref = $row['sheet'] === 'Patients' || $row['sheet'] === 'Visits' ? $d['local_patient_ref'] : ($visits[$d['local_visit_ref']] ?? null);
            if (! isset($patients[$ref])) {
                throw ValidationException::withMessages(['file' => 'صف يتيم: اربط كل واقعة بزيارة وكل زيارة بمريض موجودين في الملف.']);
            }
            $row['local_patient_ref'] = $ref;
        }
        unset($row);
        $path = 'dossier-imports/'.Str::uuid().'.xlsx';
        Storage::disk('dossier_private')->put($path, file_get_contents($file->getRealPath()));
        try {
            return DB::transaction(function () use ($r, $f, $file, $hash, $purpose, $cutover, $rows, $path) {
                $id = DB::table('dossier_import_batches')->insertGetId(['facility_id' => $f['id'], 'uploaded_by' => $r->user()->id, 'updated_by' => $r->user()->id, 'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255), 'private_path' => $path, 'file_hash' => $hash, 'template_version' => ImportWorkbook::VERSION, 'purpose' => $purpose, 'cutover_date' => $cutover, 'total_rows' => count($rows), 'file_expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('dossier_import_rows')->insert(array_map(fn ($row) => ['batch_id' => $id, 'facility_id' => $f['id'], 'sheet' => $row['sheet'], 'row_number' => $row['row_number'], 'source_record_id' => $row['source_record_id'], 'local_patient_ref' => $row['local_patient_ref'], 'local_visit_ref' => $row['data']['local_visit_ref'] ?? null, 'fingerprint' => self::fingerprint($row['data']), 'encrypted_payload' => Crypt::encryptString(json_encode($row['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'created_at' => now(), 'updated_at' => now()], $chunk));
                }
                $this->audit($r, $f, $id, 'uploaded', ['rows' => count($rows)]);

                return $id;
            });
        } catch (\Throwable $e) {
            Storage::disk('dossier_private')->delete($path);
            // Concurrent identical uploads converge on the durable facility/hash key.
            if ($e instanceof QueryException && ($e->errorInfo[1] ?? null) === 1062) {
                $id = DB::table('dossier_import_batches')->where('facility_id', $f['id'])->where('file_hash', $hash)->value('id');
                if ($id) {
                    return (int) $id;
                }
            }
            if ($e instanceof QueryException) {
                $this->databaseFailure($e);
            }
            throw $e;
        }
    }

    public function batch(array $f, int $id, bool $lock = false): object
    {
        $q = DB::table('dossier_import_batches')->where('facility_id', $f['id'])->where('id', $id);
        $batch = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($batch, 404);

        return $batch;
    }

    public function present(array $f, int $id, int $page = 1, ?string $status = null, ?string $action = null): array
    {
        $b = $this->batch($f, $id);
        $q = DB::table('dossier_import_rows')->where('batch_id', $id)->where('facility_id', $f['id']);
        $counts = (clone $q)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();
        $actions = (clone $q)->where('sheet', 'Patients')->selectRaw('action, COUNT(*) AS total')->groupBy('action')->pluck('total', 'action')->all();
        $sheets = (clone $q)->selectRaw('sheet, COUNT(*) AS total')->groupBy('sheet')->pluck('total', 'sheet')->all();
        $matches = (clone $q)->where('sheet', 'Patients')->whereNotNull('resolution')->select('resolution->match_type as type')->selectRaw('COUNT(*) AS total')->groupBy('resolution->match_type')->pluck('total', 'type')->all();
        $preCutover = (clone $q)->where('sheet', 'Patients')->where('resolution->pre_cutover', true)->count();
        $rows = (clone $q)->when($status, fn ($q) => $q->where('status', $status))->when($action, fn ($q) => $q->where('action', $action))->orderBy('id')->paginate(50, ['id', 'sheet', 'row_number', 'source_record_id', 'local_patient_ref', 'local_visit_ref', 'status', 'action', 'errors', 'resolution', 'dossier_id', 'visit_id'], 'page', $page);
        $rows->getCollection()->transform(function ($row) {
            $row->errors = (object) json_decode($row->errors ?? '{}', true);
            $resolution = json_decode($row->resolution ?? '{}', true);
            $row->match = (object) Arr::only($resolution, ['canonical_code', 'match_type']);
            unset($row->resolution);

            return $row;
        });

        return ['id' => (int) $b->id, 'facility_id' => (int) $b->facility_id, 'template_version' => $b->template_version, 'cutover_date' => $b->cutover_date, 'purpose' => $b->purpose, 'status' => $b->status, 'lock_version' => (int) $b->lock_version, 'total_rows' => $b->total_rows, 'counts' => $counts, 'actions' => $actions, 'sheets' => $sheets, 'matches' => (object) $matches, 'pre_cutover_patients' => $preCutover, 'created_at' => $b->created_at, 'file_expires_at' => $b->file_expires_at, 'rows' => $rows];
    }

    public function step(Request $r, array $f, int $id, int $version, string $operation): void
    {
        // Bounded synchronous work; lock and version cover the entire HTTP chunk.
        // A crashed request rolls back this chunk, so resuming cannot duplicate facts.
        try {
            DB::transaction(function () use ($r, $f, $id, $version, $operation) {
                $batch = $this->batch($f, $id, true);
                DossierWrites::version((array) $batch, $version);
                if ($operation === 'cancel') {
                    if (in_array($batch->status, ['completed', 'completed_with_errors', 'cancelled'])) {
                        DossierWrites::conflict('انتهت الدفعة؛ لا يمكن إلغاء وقائع محفوظة.');
                    }
                    $this->transition($r, $f, $batch, 'cancelled');

                    return;
                }
                $allowed = $operation === 'validate' ? ['uploaded', 'validating', 'needs_review', 'failed'] : ['validated', 'committing', 'completed_with_errors'];
                if (! in_array($batch->status, $allowed) || $batch->file_deleted_at) {
                    DossierWrites::conflict('حالة الدفعة لا تسمح بهذه العملية. اجلب أحدث نسخة.');
                }
                $query = DB::table('dossier_import_rows')->where('batch_id', $id)->where('facility_id', $f['id']);
                $statuses = $operation === 'validate' ? ['pending'] : ['valid'];
                $refs = (clone $query)->whereIn('status', $statuses)->select('local_patient_ref')->distinct()->orderBy('local_patient_ref')->limit(self::CHUNK)->when($operation === 'commit', fn ($q) => $q->lockForUpdate())->pluck('local_patient_ref');
                $groups = (clone $query)->whereIn('local_patient_ref', $refs)->orderBy('id')->when($operation === 'commit', fn ($q) => $q->lockForUpdate())->get()->groupBy('local_patient_ref');
                $decoded = $groups->map(fn ($group) => $group->map(fn ($row) => (array) $row + ['data' => json_decode(Crypt::decryptString($row->encrypted_payload), true, flags: JSON_THROW_ON_ERROR)])->all());
                $adapter = app(ImportBundle::class);
                if ($operation === 'commit') {
                    app(PatientCardCodes::class)->reserve();
                    // Source rows describe new facts only. Collect their lock targets
                    // without an ordinary SELECT: that would establish a repeatable-
                    // read snapshot before waiting for an assignment/code writer.
                    $staff = [];
                    $clinics = [];
                    foreach ($decoded->flatten(1) as $row) {
                        foreach ($row['data'] as $key => $value) {
                            if (in_array($key, ['clinic_id', 'prescribing_clinic_id'])) {
                                $clinics[] = $value;
                            }
                            if (in_array($key, ['doctor_id', 'diagnosing_staff_id', 'prescribing_staff_id', 'attending_staff_id'])) {
                                $staff[] = $value;
                            }
                        }
                    }
                    app(DossierClinicalContext::class)->lock([array_filter($staff), array_filter($clinics)]);
                }
                $adapter->prime($decoded->flatten(1)->all(), $f, $operation === 'commit');
                $sourceMap = DB::table('dossier_import_sources')->where('facility_id', $f['id'])->whereIn('source_record_id', $decoded->flatten(1)->pluck('source_record_id'))->when($operation === 'commit', fn ($q) => $q->lockForUpdate())->get()->keyBy(fn ($s) => $s->sheet.':'.$s->source_record_id);
                $sourceRows = DB::table('dossier_import_rows')->whereIn('id', $sourceMap->pluck('row_id'))->get()->keyBy('id');
                foreach ($refs as $ref) {
                    $stored = $groups->get($ref);
                    $rows = $decoded->get($ref);
                    try {
                        DB::transaction(function () use ($r, $f, $batch, $rows, $stored, $operation, $adapter, $sourceMap, $sourceRows) {
                            $bundle = $adapter->prepare($r, $f, $rows, $operation === 'commit');
                            $resolution = ['patient_id' => $bundle['patient']['id'] ?? null, 'patient_version' => $bundle['patient']['lock_version'] ?? null, 'dossier_id' => $bundle['dossier']['id'] ?? null, 'dossier_version' => $bundle['dossier']['lock_version'] ?? null, 'canonical_code' => $bundle['patient']['patient_code'] ?? null, 'match_type' => ! $bundle['patient'] ? 'new' : ($bundle['patient']['patient_code'] === $bundle['source_patient']['patient_code'] ? 'canonical' : 'alias_or_source'), 'pre_cutover' => $bundle['source_patient']['opening_date'] < $batch->cutover_date];
                            $skips = [];
                            foreach ($rows as $row) {
                                $source = $sourceMap->get($row['sheet'].':'.$row['source_record_id']);
                                if ($source && $source->fingerprint !== $row['fingerprint']) {
                                    throw ValidationException::withMessages(['source_record_id' => 'سبق اعتماد معرّف المصدر بمحتوى مختلف؛ يلزم مراجعة صريحة ولا يُستبدل التاريخ.']);
                                }
                                if ($source) {
                                    $skips[$row['id']] = $sourceRows->get($source->row_id);
                                }
                            }
                            if ($operation === 'validate') {
                                foreach ($stored as $row) {
                                    DB::table('dossier_import_rows')->where('id', $row->id)->update(['status' => 'valid', 'action' => isset($skips[$row->id]) ? 'skip' : ($row->sheet === 'Patients' ? ($bundle['dossier'] ? 'reuse_dossier' : ($bundle['patient'] ? 'new_dossier' : 'new_patient')) : 'new_fact'), 'resolution' => json_encode($resolution), 'errors' => null, 'updated_at' => now()]);
                                }
                                $this->audit($r, $f, $batch->id, 'match_reviewed', ['row_ids' => $stored->pluck('id')->all(), 'matched_patient_id' => $resolution['patient_id'], 'matched_dossier_id' => $resolution['dossier_id'], 'match_type' => $resolution['match_type']]);

                                return;
                            }
                            $prior = json_decode($stored->first()->resolution, true);
                            if ($prior !== $resolution) {
                                throw ValidationException::withMessages(['lock_version' => 'تغيرت الهوية أو البطاقة منذ المعاينة؛ أعد المراجعة في دفعة جديدة دون الكتابة فوق التعديل.']);
                            }
                            // A repeated patient source identifies the same retained dossier.
                            foreach ($rows as $row) {
                                if ($row['sheet'] === 'Patients' && isset($skips[$row['id']])) {
                                    $d = DB::table('patient_dossiers')->where('id', $skips[$row['id']]->dossier_id)->where('facility_id', $f['id'])->first();
                                    abort_unless($d, 409);
                                    $bundle['dossier'] = (array) $d;
                                }
                                if ($row['sheet'] === 'Visits' && isset($skips[$row['id']])) {
                                    // No appending facts to a previously committed visit via resubmission.
                                    foreach ($rows as $child) {
                                        if ($child['local_visit_ref'] === $row['local_visit_ref'] && ! isset($skips[$child['id']])) {
                                            throw ValidationException::withMessages(['local_visit_ref' => 'إضافة حقائق إلى زيارة مستوردة سابقًا تحتاج مسار تعديل الزيارة، لا إعادة الاستيراد.']);
                                        }
                                    }
                                    unset($bundle['visits'][$row['local_visit_ref']]);
                                }
                            }
                            $result = $adapter->write($r, $f, $bundle);
                            foreach ($rows as $row) {
                                $skip = $skips[$row['id']] ?? null;
                                $visit = $skip?->visit_id ?? ($result['visits'][$row['local_visit_ref']] ?? null);
                                if (! $skip) {
                                    DB::table('dossier_import_sources')->insert(['facility_id' => $f['id'], 'sheet' => $row['sheet'], 'source_record_id' => $row['source_record_id'], 'fingerprint' => $row['fingerprint'], 'row_id' => $row['id'], 'created_at' => now(), 'updated_at' => now()]);
                                }
                                DB::table('dossier_import_rows')->where('id', $row['id'])->update(['status' => $skip ? 'skipped' : 'committed', 'dossier_id' => $result['dossier_id'], 'visit_id' => $visit, 'committed_at' => now(), 'updated_at' => now()]);
                            }
                            if ($skips) {
                                $this->audit($r, $f, $batch->id, 'duplicate_skipped', ['row_ids' => array_keys($skips)]);
                            }
                            app(DossierWrites::class)->audit($r, $f, 'patient_dossier', $result['dossier_id'], null, ['import_batch_id' => $batch->id, 'source_rows' => array_column($rows, 'id'), 'purpose' => $batch->purpose, 'cutover_date' => $batch->cutover_date], 'imported');
                            $adapter->rememberCommitted($bundle);
                        });
                    } catch (ValidationException $e) {
                        // Validators return field names and fixed messages, never source values.
                        (clone $query)->where('local_patient_ref', $ref)->whereNotIn('status', ['committed', 'skipped'])->update(['status' => 'needs_review', 'errors' => json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                        $this->audit($r, $f, $id, 'bundle_needs_review', ['row_ids' => $stored->pluck('id')->all(), 'fields' => array_keys($e->errors())]);
                    }
                }
                $pending = (clone $query)->whereIn('status', $statuses)->exists();
                $errors = (clone $query)->whereIn('status', ['error', 'needs_review'])->exists();
                $state = $operation === 'validate' ? ($pending ? 'validating' : ((clone $query)->where('status', 'valid')->exists() ? 'validated' : 'needs_review')) : ($pending ? 'committing' : ($errors ? 'completed_with_errors' : 'completed'));
                $this->transition($r, $f, $batch, $state);
            }, 3);
        } catch (QueryException $e) {
            $this->databaseFailure($e);
        }
    }

    private function databaseFailure(QueryException $exception): never
    {
        // QueryException includes SQL and bound source values. Report a fresh safe
        // diagnostic, without chaining the original exception or logging its SQL.
        report(new \RuntimeException('Dossier import database failure; driver code '.(int) ($exception->errorInfo[1] ?? 0).'. Clinical transaction was rolled back.'));
        throw new HttpResponseException(response()->json(['error' => ['code' => 'DOSSIERS_UNAVAILABLE', 'message' => 'تعذر اعتماد الدفعة؛ لم تُحفظ المجموعة غير المكتملة. أعد تحميل الدفعة للمراجعة والمحاولة مجددًا.']], 500));
    }

    private function transition(Request $r, array $f, object $batch, string $state): void
    {
        $change = ['status' => $state, 'lock_version' => $batch->lock_version + 1, 'updated_by' => $r->user()->id, 'updated_at' => now()];
        if ($state === 'validated') {
            $change['validated_at'] = now();
        }
        if (str_starts_with($state, 'completed')) {
            $change['committed_at'] = now();
        }
        if ($state === 'cancelled') {
            $change['cancelled_at'] = now();
        }
        DB::table('dossier_import_batches')->where('id', $batch->id)->update($change);
        $this->audit($r, $f, $batch->id, 'state_changed', ['from' => $batch->status, 'to' => $state]);
    }

    private function audit(Request $r, array $f, int $id, string $event, array $data): void
    {
        app(DossierWrites::class)->audit($r, $f, 'dossier_import_batch', $id, null, $data, $event);
    }

    private function reference(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/', $value)) {
            throw ValidationException::withMessages(['file' => 'المراجع المحلية نص ثابت بأحرف لاتينية وأرقام و . _ : - فقط.']);
        }
    }

    public static function fingerprint(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
