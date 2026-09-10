<?php

namespace App\OpenApi;

use App\Http\Responses\AuthError;
use App\Http\Responses\DashboardError;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

class AuthDocumentTransformer
{
    public function __invoke(OpenApi $document): void
    {
        $document->components->addSecurityScheme('bearerAuth', SecurityScheme::http('bearer'));

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                if (! in_array($operation->operationId, ['login', 'currentUser', 'logout', 'dashboards', 'dashboardDetail'], true)) {
                    continue;
                }
                $login = $operation->operationId === 'login';
                $operation->security = $login ? [] : [new SecurityRequirement(['bearerAuth' => []])];

                // Successful schemas are inferred from Resources; the request and 422
                // are inferred from LoginRequest. Only middleware errors need supplementing.
                foreach ($operation->responses as $response) {
                    $response = $response instanceof Reference ? $response->resolve() : $response;
                    if ($response->code == 422 && isset($response->content['application/json'])) {
                        $schema = $response->content['application/json'];
                        if ($schema instanceof Schema) {
                            $response->content['application/json'] = $document->components->addSchema('ValidationError', $schema);
                        }
                    }
                    if ($response->code == 204) {
                        $response->content = [];
                    }
                }

                $errors = $login
                    ? [AuthError::InvalidCredentials, AuthError::InactiveAccount, AuthError::TooManyRequests]
                    : [AuthError::Unauthenticated, AuthError::InactiveAccount, AuthError::MissingApiAbility];
                if (in_array($operation->operationId, ['dashboards', 'dashboardDetail'], true)) {
                    $errors[] = DashboardError::FacilityForbidden;
                    $errors[] = DashboardError::Unavailable;
                }
                if ($operation->operationId === 'dashboardDetail') {
                    $errors[] = DashboardError::Forbidden;
                    $errors[] = DashboardError::NotFound;
                }
                $forbidden = [];
                foreach ($errors as $error) {
                    // Schema literals and examples come from the actual response contract.
                    $properties = new ObjectType;
                    foreach ($error->body()['error'] as $key => $value) {
                        $properties->addProperty($key, (new StringType)->enum([$value])->example($value));
                    }
                    $schema = Schema::fromType((new ObjectType)
                        ->addProperty('error', $properties->setRequired(['code', 'message']))
                        ->setRequired(['error']));
                    $reference = $document->components->addSchema($error->name.'Error', $schema);
                    if (! $login && $error->status() === 403) {
                        $forbidden[] = $reference;

                        continue;
                    }
                    $operation->addResponse(Response::make($error->status())
                        ->setDescription($error->body()['error']['message'])->setContent('application/json', $reference));
                }
                if ($forbidden) {
                    $operation->addResponse(Response::make(403)
                        ->setDescription('Account is inactive (all its tokens are revoked), the token lacks the api ability, or access to the requested dashboard/facility is denied.')
                        ->setContent('application/json', Schema::fromType((new AnyOf)->setItems($forbidden))));
                }
            }
        }
    }
}
