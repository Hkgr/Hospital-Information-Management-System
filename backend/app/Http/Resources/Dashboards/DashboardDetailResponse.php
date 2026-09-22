<?php

namespace App\Http\Resources\Dashboards;

use App\Http\Resources\Auth\FacilityResource;
use App\Http\Resources\Auth\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardDetailResponse extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'dashboard' => new DashboardResource($this->resource['dashboard']),
            'user' => new UserResource($this->resource['user']),
            'facilities' => FacilityResource::collection($this->resource['facilities']),
            /** @var int|null */
            'selected_facility_id' => $this->resource['selected_facility_id'],
            /** @var list<array{key: string, title: string}> Known local destinations the caller is authorized to open. */
            'links' => $this->resource['links'],
            'stats' => new DashboardStatsResource($this->resource['stats']),
        ];
    }
}
