<?php

declare(strict_types=1);

namespace Skir\Server\Exceptions;

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

    public static function invalidConfiguredCodec(mixed $codec): self
    {
        $codecType = is_string($codec) ? $codec : get_debug_type($codec);

        return new self(
            "Configured Skir codec [{$codecType}] must implement [Skir\\Server\\Codecs\\SkirCodec].",
            'skir_invalid_configured_codec',
            500,
        );
    }

    public static function invalidRouteProvider(mixed $provider): self
    {
        if (is_string($provider)) {
            $providerType = $provider;
        } elseif (is_object($provider)) {
            $providerType = $provider::class;
        } else {
            $providerType = get_debug_type($provider);
        }

        return new self(
            "Skir route provider [{$providerType}] must implement [Skir\\Server\\Routing\\SkirRouteDefinition] or [Skir\\Server\\ProcedureProvider].",
            'skir_invalid_route_provider',
            500,
        );
    }

    public static function missingCborDependency(): self
    {
        return new self(
            'Skir CBOR support requires the [spomky-labs/cbor-php] Composer package.',
            'skir_missing_cbor_dependency',
            500,
        );
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
