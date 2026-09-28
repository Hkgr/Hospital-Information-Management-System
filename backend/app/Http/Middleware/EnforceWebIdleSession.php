<?php

namespace App\Http\Middleware;

use App\Services\Auth\WebSession;
use Closure;
use Illuminate\Http\Request;

class EnforceWebIdleSession
{
    public function handle(Request $request, Closure $next)
    {
        app(WebSession::class)->check($request);
        // An authorized write admitted before expiry may complete. Never turn
        // an already committed save into a false 401 after downstream execution.
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Authorization');

        return $response;
    }
}
