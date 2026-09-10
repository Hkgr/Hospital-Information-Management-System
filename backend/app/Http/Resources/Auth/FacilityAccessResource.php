<?php

namespace App\Http\Resources\Auth;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('FacilityAccess')]
class FacilityAccessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'facility' => new FacilityResource($this->resource['facility']),
            'roles' => RoleResource::collection($this->resource['roles']),
            /** @var list<string> */
            'permissions' => $this->resource['permissions'],
        ];
    }
}
