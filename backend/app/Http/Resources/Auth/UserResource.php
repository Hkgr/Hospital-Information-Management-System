<?php

namespace App\Http\Resources\Auth;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('User')]
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            /** @var int|null */
            'staff_id' => $this->staff_id,
            'username' => (string) $this->username,
            'name' => (string) $this->name,
            /** @var string|null */
            'email' => $this->email,
            'must_change_password' => (bool) $this->must_change_password,
            /**
             * @var string|null
             *
             * @format date-time
             */
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
        ];
    }
}
