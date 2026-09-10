<?php

namespace Tests\Feature;

use Tests\Support\AssertsOpenApi;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use AssertsOpenApi;

    public function test_documentation_ui_and_generated_openapi_contract(): void
    {
        config(['scramble.enabled' => true]);
        $this->get('/docs/api')->assertOk()->assertSee('Hospital Information Management System API');
        $response = $this->getJson('/docs/api.json')->assertOk();
        $doc = $response->json();
        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame('Hospital Information Management System API', $doc['info']['title']);
        $this->assertSame('1.0.0', $doc['info']['version']);
        $this->assertSame('API for the Emirati Hospital information management system.', $doc['info']['description']);
        $this->assertEqualsCanonicalizing(['/api/login', '/api/user', '/api/logout', '/api/dashboards', '/api/dashboards/{key}'], array_keys($doc['paths']));
        $this->assertSame(['type' => 'http', 'scheme' => 'bearer'], $doc['components']['securitySchemes']['bearerAuth']);
        foreach ([['/api/login', 'post', 'login', [200, 401, 403, 422, 429]],
            ['/api/user', 'get', 'currentUser', [200, 401, 403]],
            ['/api/logout', 'post', 'logout', [204, 401, 403]]] as [$path, $method, $id, $statuses]) {
            $operation = $doc['paths'][$path][$method];
            $this->assertSame($id, $operation['operationId']);
            $this->assertSame(['Authentication'], $operation['tags']);
            $this->assertNotEmpty($operation['summary']);
            $this->assertNotEmpty($operation['description']);
            $this->assertEqualsCanonicalizing($statuses, array_keys($operation['responses']));
            $this->assertSame($id === 'login' ? [] : [['bearerAuth' => []]], $operation['security']);
            if ($id !== 'login') {
                $this->assertStringContainsString('api ability', $operation['description']);
                $this->assertCount(2, $operation['responses'][403]['content']['application/json']['schema']['anyOf']);
            }
        }
        $this->assertArrayNotHasKey('content', $doc['paths']['/api/logout']['post']['responses'][204]);
        foreach (['LoginRequest', 'LoginResponse', 'CurrentUserResponse', 'User', 'FacilityAccess',
            'Facility', 'Role', 'InvalidCredentialsError', 'InactiveAccountError', 'ValidationError',
            'UnauthenticatedError', 'TooManyRequestsError', 'MissingApiAbilityError'] as $name) {
            $this->assertArrayHasKey($name, $doc['components']['schemas']);
        }
        $request = $doc['components']['schemas']['LoginRequest'];
        $this->assertSame(['username', 'password'], $request['required']);
        $this->assertSame(60, $request['properties']['username']['maxLength']);
        $this->assertSame(100, $request['properties']['device_name']['maxLength']);
        $this->assertSame('hospital-web', $request['properties']['device_name']['default']);
        $this->assertSame('password', $request['properties']['password']['format']);
        $user = $doc['components']['schemas']['User'];
        $this->assertEqualsCanonicalizing(['id', 'staff_id', 'username', 'name', 'email', 'must_change_password', 'last_login_at'], array_keys($user['properties']));
        foreach (['staff_id', 'email', 'last_login_at'] as $nullable) {
            $this->assertContains('null', $user['properties'][$nullable]['type']);
        }
        $this->assertContains('null', $doc['components']['schemas']['LoginResponse']['properties']['expires_at']['type']);
        $this->assertSame('array', $doc['components']['schemas']['CurrentUserResponse']['properties']['access']['type']);
        $this->assertArrayNotHasKey('token', $doc['components']['schemas']['CurrentUserResponse']['properties']);
        $this->assertArrayNotHasKey('password', $user['properties']);
        $this->assertArrayNotHasKey('remember_token', $user['properties']);
        foreach (['DB_PASSWORD', 'DB_USERNAME', 'mysql', 'stack_trace', 'tokenable_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        // Resolve every reference from the actual JSON, including response components.
        $walk = function (array $node) use (&$walk, $doc): void {
            if (isset($node['$ref'])) {
                $this->assertNotEmpty($this->resolveSchema($doc, $node));
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($doc);
    }

    public function test_documentation_defaults_to_local_and_testing_and_honors_explicit_switch(): void
    {
        foreach (['local', 'testing', 'production'] as $environment) {
            $this->app->instance('env', $environment);
            config(['scramble.enabled' => null]);
            $status = $environment === 'production' ? 404 : 200;
            $this->get('/docs/api')->assertStatus($status);
            $this->getJson('/docs/api.json')->assertStatus($status);
            config(['scramble.enabled' => false]);
            $this->get('/docs/api')->assertNotFound();
            $this->getJson('/docs/api.json')->assertNotFound();
        }
        config(['scramble.enabled' => true]);
        $this->get('/docs/api')->assertOk();
        $this->getJson('/docs/api.json')->assertOk();
    }
}
