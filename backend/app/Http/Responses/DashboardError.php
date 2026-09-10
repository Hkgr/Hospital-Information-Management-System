<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

enum DashboardError: string
{
    case NotFound = 'DASHBOARD_NOT_FOUND';
    case Forbidden = 'DASHBOARD_ACCESS_DENIED';
    case FacilityForbidden = 'FACILITY_ACCESS_DENIED';
    case Unavailable = 'DASHBOARD_UNAVAILABLE';

    public function status(): int
    {
        return match ($this) {
            self::NotFound => 404, self::Unavailable => 500, default => 403
        };
    }

    public function body(): array
    {
        return ['error' => ['code' => $this->value, 'message' => match ($this) {
            self::NotFound => 'لوحة التحكم المطلوبة غير موجودة.',
            self::Forbidden => 'ليس لديك صلاحية الوصول إلى لوحة التحكم هذه.',
            self::FacilityForbidden => 'ليس لديك وصول إلى المنشأة المطلوبة.',
            self::Unavailable => 'تعذّر تحميل لوحة التحكم. حاول مجددًا.',
        }]];
    }

    public function response(): JsonResponse
    {
        return response()->json($this->body(), $this->status());
    }
}
