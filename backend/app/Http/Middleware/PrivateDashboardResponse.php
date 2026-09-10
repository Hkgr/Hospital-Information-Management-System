<?php

namespace App\Http\Middleware;

use App\Http\Responses\DashboardError;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateDashboardResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/dashboards', 'api/dashboards/*')) {
            return $next($request);
        }
        // Render downstream exceptions here so even authentication/validation errors
        // receive the private policy. Never cache personalized responses at a proxy.
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $handler = app(ExceptionHandler::class);
            $handler->report($exception);
            $response = $handler->render($request, $exception);
        }
        if ($response->getStatusCode() >= 500) {
            $response = DashboardError::Unavailable->response();
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Authorization');

        return $response;
    }
}
