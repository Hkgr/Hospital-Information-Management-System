<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;

class LoginResponse extends CurrentUserResponse
{
    public function toArray(Request $request): array
    {
        return [
            /** @example 1|example-plain-text-token */
            'token' => (string) $this->resource['token']->plainTextToken,
            'token_type' => 'Bearer',
            /**
             * @var string|null
             *
             * @format date-time
             */
            'expires_at' => $this->resource['token']->accessToken->expires_at?->toIso8601ZuluString(),
            ...parent::toArray($request),
        ];
    }
}
