<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateClinicResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/clinics', 'api/clinics/*')) {
            return $next($request);
        }
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $handler = app(ExceptionHandler::class);
            $handler->report($exception);
            $response = $handler->render($request, $exception);
        }
        if ($response->getStatusCode() >= 500) {
            $response = response()->json(['error' => ['code' => 'CLINICS_UNAVAILABLE', 'message' => 'تعذّر إتمام العملية. حاول مجددًا.']], 500);
        }
        if ($response->getStatusCode() === 404) {
            $response = response()->json(['error' => ['code' => 'CLINIC_NOT_FOUND', 'message' => 'العيادة أو المسار غير موجود في المنشأة المحددة.']], 404);
        }
        if ($response->getStatusCode() === 405) {
            $response = response()->json(['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'طريقة الطلب غير مدعومة.']], 405);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Authorization');

        return $response;
    }
}
