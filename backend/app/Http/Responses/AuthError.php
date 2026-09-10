<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

enum AuthError: string
{
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case InactiveAccount = 'ACCOUNT_INACTIVE';
    case Unauthenticated = 'UNAUTHENTICATED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';

    public function status(): int
    {
        return match ($this) {
            self::InvalidCredentials, self::Unauthenticated => 401,
            self::InactiveAccount => 403,
            self::TooManyRequests => 429,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::InvalidCredentials => 'اسم المستخدم أو كلمة المرور غير صحيحة.',
            self::InactiveAccount => 'هذا الحساب غير فعال.',
            self::Unauthenticated => 'يلزم تسجيل الدخول.',
            self::TooManyRequests => 'محاولات كثيرة. حاول مرة أخرى بعد دقيقة.',
        };
    }

    /** @return array{error: array{code: string, message: string}} */
    public function body(): array
    {
        return ['error' => ['code' => $this->value, 'message' => $this->message()]];
    }

    public function response(array $headers = []): JsonResponse
    {
        return response()->json($this->body(), $this->status(), $headers);
    }
}
