<?php

declare(strict_types=1);

namespace Skir\Server\Exceptions;

use RuntimeException;

final class UnsupportedTerminableMiddlewareException extends RuntimeException
{
    public static function forMiddleware(string $middleware): self
    {
        return new self(
            "Skir controller middleware [{$middleware}] defines terminate(), which procedure middleware pipelines do not support.",
        );
    }
}
