<?php

declare(strict_types=1);

namespace Skir\Server\Exceptions;

use RuntimeException;

final class UnsupportedControllerParameterException extends RuntimeException
{
    /** @param class-string $controller */
    public static function compoundType(
        string $controller,
        string $method,
        string $parameter,
        string $type,
    ): self {
        return new self(
            "Skir controller [{$controller}::{$method}] parameter [\${$parameter}] uses unsupported compound type [{$type}]. Only unions containing exclusively builtin types can receive the decoded Skir payload.",
        );
    }
}
