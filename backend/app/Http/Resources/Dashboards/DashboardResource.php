<?php

namespace App\Http\Resources\Dashboards;

use App\Http\Resources\Auth\FacilityResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => (string) $this->resource['key'],
            'title' => (string) $this->resource['title'],
            'requires_facility' => (bool) $this->resource['requires_facility'],
            'facilities' => FacilityResource::collection($this->resource['facilities']),
            /** @var int|null */
            'default_facility_id' => $this->resource['default_facility_id'],
        ];
    }
}
