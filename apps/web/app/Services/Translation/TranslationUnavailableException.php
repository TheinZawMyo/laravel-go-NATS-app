<?php

namespace App\Services\Translation;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Raised when no translation provider is configured or the configured
 * provider fails. It is surfaced as a retryable 503 rather than a 500 so the
 * client can show a clear message without exposing provider details.
 */
class TranslationUnavailableException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 503);
    }
}
