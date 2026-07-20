<?php

declare(strict_types=1);

namespace Skir\Server\Exceptions;

use RuntimeException;

final class AmbiguousControllerRequestException extends RuntimeException
{
    /**
     * @param  class-string  $controller
     * @param  list<string>  $parameters
     */
    public static function forController(
        string $controller,
        string $method,
        array $parameters,
    ): self {
        $parameterList = implode(', ', array_map(
            static fn (string $parameter): string => '$'.$parameter,
            $parameters,
        ));

        return new self(
            "Skir controller [{$controller}::{$method}] has multiple direct request parameters [{$parameterList}].",
        );
    }
}
