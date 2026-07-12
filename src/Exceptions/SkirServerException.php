<?php

declare(strict_types=1);

namespace Skir\Server\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class SkirServerException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $details
     */
    private function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $details = [],
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

    public static function missingCborDependency(): self
    {
        return new self(
            'Skir CBOR support requires the [spomky-labs/cbor-php] Composer package.',
            'skir_missing_cbor_dependency',
            500,
        );
    }

    /** @param array<string, array<int, string>> $errors */
    public static function validationFailed(array $errors): self
    {
        return new self('The given data was invalid.', 'skir_validation_failed', 422, $errors);
    }

    public static function authorizationFailed(): self
    {
        return new self('This action is unauthorized.', 'skir_authorization_failed', 403);
    }

    public function toResponse(): JsonResponse
    {
        $error = [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];

        if ($this->details !== []) {
            $error['details'] = $this->details;
        }

        return response()->json([
            'error' => $error,
        ], $this->status);
    }
}
