<?php

namespace App\Http\Resources\Auth;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('Facility')]
class FacilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource['id'],
            'code' => (string) $this->resource['code'],
            'name_ar' => (string) $this->resource['name_ar'],
            'timezone' => (string) $this->resource['timezone'],
        ];
    }
}
