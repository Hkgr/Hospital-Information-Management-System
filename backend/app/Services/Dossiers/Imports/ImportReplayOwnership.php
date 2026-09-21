<?php

namespace App\Services\Dossiers\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Resolve retained source identity before preparing any clinical writer input. */
class ImportReplayOwnership
{
    private Collection $retained;

    private Collection $parents;

    private Collection $dossiers;

    private Collection $visits;

    public function __construct(private array $facility, private Collection $sources, bool $lock)
    {
        if ($sources->isEmpty()) {
            $this->retained = $this->parents = $this->dossiers = $this->visits = collect();

            return;
        }
        $this->retained = DB::table('dossier_import_rows')->where('facility_id', $facility['id'])->whereIn('id', $sources->pluck('row_id'))
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        // Only parents of this bounded chunk's retained sources, not entire old workbooks.
        $this->parents = collect();
        if ($this->retained->isNotEmpty()) {
            $this->parents = DB::table('dossier_import_rows')->where('facility_id', $facility['id'])->whereIn('sheet', ['Patients', 'Visits', 'Prescriptions'])
                ->where(function ($q) {
                    foreach ($this->retained->groupBy('batch_id') as $batch => $rows) {
                        $q->orWhere(fn ($q) => $q->where('batch_id', $batch)->where(function ($q) use ($rows) {
                            $q->where(fn ($q) => $q->where('sheet', 'Patients')->whereIn('local_patient_ref', $rows->pluck('local_patient_ref')))
                                ->orWhereIn('local_visit_ref', $rows->pluck('local_visit_ref')->filter(fn ($ref) => $ref !== null));
                        }));
                    }
                })->when($lock, fn ($q) => $q->lockForUpdate())->get()
                ->groupBy(fn ($row) => $this->parentKey($row, $row->sheet));
        }
        $this->dossiers = DB::table('patient_dossiers')->where('facility_id', $facility['id'])->whereIn('id', $this->retained->pluck('dossier_id'))
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
        $this->visits = DB::table('visits')->where('facility_id', $facility['id'])->whereIn('id', $this->retained->pluck('visit_id')->filter())
            ->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');
    }

    /** @return array{0: array, 1: array} Retained skips and strictly new clinical input. */
    public function resolve(array $rows): array
    {
        $this->require(count($rows) <= 500);
        $skips = [];
        foreach ($rows as $row) {
            $source = $this->sources->get($row['sheet'].':'.$row['source_record_id']);
            if (! $source) {
                continue;
            }
            $old = $this->retained->get($source->row_id);
            $this->require($old && $old->status === 'committed' && $old->committed_at
                && $old->facility_id === $this->facility['id'] && $old->sheet === $row['sheet']
                && $old->source_record_id === $row['source_record_id']
                && $old->fingerprint === $row['fingerprint'] && $source->fingerprint === $row['fingerprint']);
            $dossier = $this->dossiers->get($old->dossier_id);
            $this->require($dossier !== null);
            $patient = $this->parent($old, 'Patients');
            $this->require($patient->dossier_id === $old->dossier_id && $patient->visit_id === null);
            if ($old->sheet === 'Patients') {
                $this->require($old->visit_id === null);
            } else {
                $visit = $this->visits->get($old->visit_id);
                $this->require($visit && $visit->dossier_id === $dossier->id && $visit->patient_id === $dossier->patient_id);
                $parent = $this->parent($old, 'Visits');
                $this->require($parent->visit_id === $visit->id && $parent->dossier_id === $dossier->id && $parent->local_patient_ref === $old->local_patient_ref);
            }
            $skips[$row['id']] = $old;
        }
        $visits = collect($rows)->where('sheet', 'Visits')->keyBy('local_visit_ref');
        $prescriptions = collect($rows)->where('sheet', 'Prescriptions')->keyBy('local_visit_ref');
        foreach ($rows as $row) {
            if (in_array($row['sheet'], ['Patients', 'Visits'])) {
                continue;
            }
            $visit = $visits->get($row['local_visit_ref']);
            $oldVisit = $skips[$visit['id'] ?? 0] ?? null;
            $old = $skips[$row['id']] ?? null;
            // No new facts on replayed visits, nor replayed facts on new visits.
            $this->require((bool) $old === (bool) $oldVisit);
            if ($old) {
                $this->sameParent($old, 'Visits', $oldVisit);
                if ($row['sheet'] === 'Medications') {
                    $rx = $prescriptions->get($row['local_visit_ref']);
                    $oldRx = $skips[$rx['id'] ?? 0] ?? null;
                    $this->require($oldRx !== null);
                    $this->sameParent($old, 'Prescriptions', $oldRx);
                }
            }
        }

        // Patient input is still needed to resolve identity; no skipped clinical
        // row, including a visit header, can enter ImportBundle::prepare/write.
        return [$skips, array_values(array_filter($rows, fn ($row) => $row['sheet'] === 'Patients' || ! isset($skips[$row['id']])))];
    }

    public function assertDossier(array $skips, array $bundle): void
    {
        foreach ($skips as $old) {
            $dossier = $this->dossiers->get($old->dossier_id);
            $this->require($old->dossier_id === ($bundle['dossier']['id'] ?? null)
                && $dossier->patient_id === ($bundle['patient']['id'] ?? null));
        }
    }

    private function parentKey(object $row, string $sheet): string
    {
        return $row->batch_id.':'.$sheet.':'.($sheet === 'Patients' ? $row->local_patient_ref : $row->local_visit_ref);
    }

    private function parent(object $row, string $sheet): object
    {
        $parents = $this->parents->get($this->parentKey($row, $sheet), collect());
        $this->require($parents->count() === 1);
        $parent = $parents->first();
        $this->require(in_array($parent->status, ['committed', 'skipped']) && $parent->committed_at !== null);

        return $parent;
    }

    private function sameParent(object $child, string $sheet, object $current): void
    {
        $parent = $this->parent($child, $sheet);
        $this->require($parent->source_record_id === $current->source_record_id && $parent->fingerprint === $current->fingerprint
            && $parent->visit_id === $current->visit_id && $parent->dossier_id === $current->dossier_id
            && $child->visit_id === $current->visit_id && $child->dossier_id === $current->dossier_id);
    }

    private function require(bool $valid): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['source_record_id' => 'تعذّر إثبات تطابق المصدر المحفوظ وملكيته للمريض والزيارة والوصفة؛ يلزم مراجعة المجموعة كاملة دون إعادة كتابة الحقائق.']);
        }
    }
}
