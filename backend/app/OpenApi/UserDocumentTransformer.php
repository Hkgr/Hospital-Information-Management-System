<?php

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class UserDocumentTransformer extends ClinicDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        foreach ($document->paths as $path) {
            $route = preg_replace('#^api/#', '', trim($path->path, '/'));
            if (! preg_match('#^users(/|$)#', $route)) {
                continue;
            }
            foreach ($path->operations as $op) {
                $op->security = [new SecurityRequirement(['bearerAuth' => []])];
                $op->description = 'Facility user administration. Requires an active account, Sanctum Bearer api ability, facility membership, users.view, and users.create/users.delete for writes. Definitions are seeded; this module never grants or reactivates permissions. Passwords are write-only. User 1 is not exempt. Last membership unlinks, revokes tokens and deactivates; the row is hard-deleted only when unreferenced. Self-delete returns 409. All responses private, no-store.';
                $s = fn () => new StringType;
                $i = fn () => new IntegerType;
                $role = $this->object(['id' => $i(), 'code' => $s(), 'name_ar' => $s()]);
                $user = $this->object(['id' => $i(), 'username' => $s(), 'name' => $s(), 'email' => $s()->nullable(true), 'is_active' => new BooleanType, 'last_login_at' => $s()->nullable(true), 'roles' => $this->list($role)]);
                $meta = $this->object(array_fill_keys(['page', 'per_page', 'total', 'last_page'], $i()));
                $op->responses = [];
                if ($op->method === 'delete') {
                    $op->addResponse(Response::make(204)->setDescription('Unlinked from the facility; account deactivated when last membership'));
                } elseif ($route === 'users/options') {
                    $op->addResponse(Response::make(200)->setDescription('Active roles and scoped capabilities')->setContent('application/json', Schema::fromType($this->object(['data' => $this->object([
                        'roles' => $this->list($role),
                        'capabilities' => $this->object(['view' => new BooleanType, 'create' => new BooleanType, 'delete' => new BooleanType]),
                    ])]))));
                } elseif ($op->method === 'post') {
                    $op->addResponse(Response::make(201)->setDescription('Created facility member')->setContent('application/json', Schema::fromType($this->object(['data' => $user]))));
                } else {
                    $op->addResponse(Response::make(200)->setDescription('Authorized facility members')->setContent('application/json', Schema::fromType($this->object(['data' => $this->list($user), 'meta' => $meta]))));
                }
                foreach ([401 => 'Unauthenticated', 403 => 'USERS_ACCESS_DENIED', 404 => 'USERS_NOT_FOUND', 409 => 'USER_SELF_DELETE', 422 => 'Validation or USER_USERNAME_TAKEN/USER_EMAIL_TAKEN/USER_ROLE_INVALID', 500 => 'USERS_UNAVAILABLE; no internal details'] as $status => $description) {
                    if ($status === 409 && $op->method !== 'delete') {
                        continue;
                    }
                    $op->addResponse(Response::make($status)->setDescription($description));
                }
            }
        }
    }
}
