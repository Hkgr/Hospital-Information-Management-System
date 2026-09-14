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
        if (! $request->is('api/clinics', 'api/clinics/*', 'api/doctors', 'api/doctors/*', 'api/service-catalog', 'api/service-catalog/*', 'api/blood-bank', 'api/blood-bank/*')) {
            return $next($request);
        }
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $handler = app(ExceptionHandler::class);
            $handler->report($exception);
            $response = $handler->render($request, $exception);
        }
        $doctors = $request->is('api/doctors', 'api/doctors/*');
        $catalog = $request->is('api/service-catalog', 'api/service-catalog/*');
        $blood = $request->is('api/blood-bank', 'api/blood-bank/*');
        if ($response->getStatusCode() >= 500) {
            $response = response()->json(['error' => ['code' => $catalog ? 'CATALOG_UNAVAILABLE' : ($doctors ? 'DOCTORS_UNAVAILABLE' : 'CLINICS_UNAVAILABLE'), 'message' => 'تعذّر إتمام العملية. حاول مجددًا.']], 500);
        }
        if ($response->getStatusCode() === 404) {
            $response = response()->json(['error' => ['code' => $catalog ? 'CATALOG_NOT_FOUND' : ($doctors ? 'DOCTOR_NOT_FOUND' : 'CLINIC_NOT_FOUND'), 'message' => $catalog ? 'العنصر أو المسار غير موجود في الدليل المتاح.' : ($doctors ? 'الطبيب أو المسار غير موجود في الدليل المتاح.' : 'العيادة أو المسار غير موجود في المنشأة المحددة.')]], 404);
        }
        if ($response->getStatusCode() === 405) {
            $response = response()->json(['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'طريقة الطلب غير مدعومة.']], 405);
        }
        if ($blood && in_array($response->getStatusCode(), [404, 500], true)) {
            $status = $response->getStatusCode();
            $response = response()->json(['error' => ['code' => $status === 404 ? 'BLOOD_BANK_NOT_FOUND' : 'BLOOD_BANK_UNAVAILABLE', 'message' => $status === 404 ? 'السجل أو المسار غير موجود في بنك الدم المتاح.' : 'تعذّر إتمام عملية بنك الدم. حاول مجددًا.']], $status);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Authorization');

        return $response;
    }
}
