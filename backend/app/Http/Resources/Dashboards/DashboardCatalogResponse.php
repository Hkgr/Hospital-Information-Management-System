<?php

namespace App\Http\Resources\Dashboards;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardCatalogResponse extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'dashboards' => DashboardResource::collection($this->resource['dashboards']),
            /** @var string|null */
            'default_dashboard_key' => $this->resource['default_dashboard_key'],
        ];
    }
}
