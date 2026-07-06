<?php

declare(strict_types=1);

namespace LaravelSkir\Server;

use LaravelSkir\Runtime\MethodDescriptor;
use LaravelSkir\Server\Exceptions\SkirServerException;

final class ProcedureRegistry
{
    /** @var array<string, RegisteredProcedure> */
    private array $procedures = [];

    public function add(MethodDescriptor $descriptor, callable $handler): void
    {
        if (array_key_exists($descriptor->name, $this->procedures)) {
            throw SkirServerException::duplicateMethod($descriptor->name);
        }

        $this->procedures[$descriptor->name] = new RegisteredProcedure($descriptor, $handler);
    }

    public function get(string $method): RegisteredProcedure
    {
        return $this->procedures[$method] ?? throw SkirServerException::methodNotFound($method);
    }

    /**
     * @return array<string, RegisteredProcedure>
     */
    public function all(): array
    {
        return $this->procedures;
    }
}
