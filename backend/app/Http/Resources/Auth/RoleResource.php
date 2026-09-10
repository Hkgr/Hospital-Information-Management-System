<?php

namespace App\Http\Resources\Auth;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('Role')]
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => (string) $this->resource['code'],
            'name_ar' => (string) $this->resource['name_ar'],
            /** @var string|null */
            'name_en' => $this->resource['name_en'],
        ];
    }
}
