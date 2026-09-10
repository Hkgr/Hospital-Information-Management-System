<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiDocsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        // The official Scramble middleware bypasses its Gate in local environments.
        // Check the explicit switch first, including when routes are cached.
        abort_unless(config('scramble.enabled') ?? app()->environment(['local', 'testing']), 404);

        return $next($request);
    }
}
