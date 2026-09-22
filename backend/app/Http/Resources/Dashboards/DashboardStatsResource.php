<?php

namespace App\Http\Resources\Dashboards;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('DashboardStats')]
class DashboardStatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /** @var list<array{key: string, label: string, value: int}> */
            'counters' => $this->resource['counters'],
            /** @var list<array{key: string, label: string, value: int}> */
            'visit_status' => $this->resource['visit_status'],
            /** @var list<array{key: string, label: string, value: int}> */
            'dossier_status' => $this->resource['dossier_status'],
            /** @var list<array{id: int, name_ar: string, visit_count: int}> */
            'clinics' => $this->resource['clinics'],
            /** @var list<array{id: int, name_ar: string, visit_count: int}> */
            'doctors' => $this->resource['doctors'],
            /** @var list<array{id: int, planned_on: string, session_number: int, dossier_id: int, patient_code: string, patient_name: string, clinic_name: string, doctor_name: string}> */
            'appointments' => $this->resource['appointments'],
        ];
    }
}
