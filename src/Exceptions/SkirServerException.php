<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class SkirServerException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalidRequest(string $message): self
    {
        return new self($message, 'skir_invalid_request', 422);
    }

    public static function methodNotFound(string $method): self
    {
        return new self("Skir method [{$method}] is not registered.", 'skir_method_not_found', 404);
    }

    public static function duplicateMethod(string $method): self
    {
        return new self("Skir method [{$method}] is already registered on this endpoint.", 'skir_duplicate_method', 422);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
            ],
        ], $this->status);
    }
}
