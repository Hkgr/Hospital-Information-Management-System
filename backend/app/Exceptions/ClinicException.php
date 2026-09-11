<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class ClinicException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function report(): bool
    {
        return true; // Expected domain failures, not programming/database errors.
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()]], $this->status);
    }
}
