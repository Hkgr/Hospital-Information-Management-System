<?php

namespace App\Http\Middleware;

use App\Http\Responses\AuthError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sanctum loads the user from the database for each authenticated request.
        $user = $request->user();
        if (! $user->is_active) {
            $user->tokens()->delete();

            return AuthError::InactiveAccount->response();
        }

        return $next($request);
    }
}
