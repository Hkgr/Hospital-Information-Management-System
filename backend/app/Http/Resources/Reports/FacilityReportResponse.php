<?php

namespace App\Http\Resources\Reports;

use App\Http\Resources\Auth\FacilityResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('FacilityReport')]
class FacilityReportResponse extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'facility' => new FacilityResource($this->resource['facility']),
            /** @var array{key: string, label: string, starts_on: string, ends_on: string} */
            'period' => $this->resource['period'],
            /** @var list<array{key: string, label: string, value: int}> */
            'counters' => $this->resource['counters'],
            /** @var list<array{key: string, label: string, value: int}> */
            'visit_status' => $this->resource['visit_status'],
            /** @var list<array{key: string, label: string, value: int}> */
            'series' => $this->resource['series'],
            /** @var list<array{key: string, label: string, value: int}> */
            'mix' => $this->resource['mix'],
            /** @var list<array{id: int, name_ar: string, visit_count: int}> */
            'clinics' => $this->resource['clinics'],
            /** @var list<array{id: int, name_ar: string, visit_count: int}> */
            'doctors' => $this->resource['doctors'],
            /** @var list<array{id: int, name_ar: string, visit_count: int}> */
            'procedures' => $this->resource['procedures'],
            /** @var list<array{id: int, dossier_id: int|null, patient_code: string, patient_name: string, visit_count: int, last_visit_on: string}> */
            'patients' => $this->resource['patients'],
            /** @var string */
            'patients_definition' => $this->resource['patients_definition'],
        ];
    }
}
