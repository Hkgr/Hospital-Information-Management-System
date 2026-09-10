<?php

namespace Tests\Feature;

use App\Http\Middleware\PrivateDashboardResponse;
use App\Http\Responses\AuthError;
use App\Http\Responses\DashboardError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PrivateDashboardResponseTest extends TestCase
{
    public function test_caught_unexpected_exception_is_reported_once_and_response_remains_private(): void
    {
        config(['app.debug' => true]);
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reported) {
            $reported[] = $exception;

            return false; // Observe Laravel reporting without writing test details to logs.
        });

        foreach (['/api/dashboards', '/api/dashboards/general'] as $path) {
            $reported = [];
            $exception = new RuntimeException('SQLSTATE test failure; SELECT private_data; password=test-only-secret');
            $response = $this->catchException($path, $exception);

            $response->assertStatus(500)->assertExactJson(DashboardError::Unavailable->body())
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Authorization');
            foreach ([$exception->getMessage(), 'SQLSTATE', 'SELECT', 'test-only-secret', 'trace'] as $detail) {
                $this->assertStringNotContainsString($detail, $response->getContent());
            }
            $this->assertSame([$exception], $reported, 'The caught exception must reach Laravel reporting exactly once.');
        }
    }

    public function test_expected_exceptions_keep_their_contracts_and_laravel_reporting_exclusions(): void
    {
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reported) {
            $reported[] = $exception;

            return false;
        });

        foreach ([
            [new AuthenticationException, 401, AuthError::Unauthenticated->body()],
            [ValidationException::withMessages(['facility_id' => 'Invalid facility.']), 422,
                ['message' => 'Invalid facility.', 'errors' => ['facility_id' => ['Invalid facility.']]]],
            [new HttpResponseException(DashboardError::Forbidden->response()), 403, DashboardError::Forbidden->body()],
        ] as [$exception, $status, $body]) {
            $this->catchException('/api/dashboards/general', $exception)->assertStatus($status)->assertExactJson($body)
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Authorization');
        }
        $this->assertSame([], $reported, 'Laravel must continue excluding expected exceptions from reporting.');
    }

    private function catchException(string $path, Throwable $exception): TestResponse
    {
        $request = Request::create($path, 'GET', server: ['HTTP_ACCEPT' => 'application/json']);

        // Exercise this middleware's catch, independently of the router's own reporting.
        return TestResponse::fromBaseResponse(app(PrivateDashboardResponse::class)->handle(
            $request, fn () => throw $exception
        ));
    }
}
