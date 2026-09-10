<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrentUserResponse extends JsonResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'no-store');
    }

    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource['user']),
            'access' => FacilityAccessResource::collection($this->resource['access']),
        ];
    }
}
