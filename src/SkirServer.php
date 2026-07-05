<?php

declare(strict_types=1);

namespace LaravelSkir\Server;

use LaravelSkir\Runtime\MethodDescriptor;

final readonly class SkirServer
{
    public function __construct(
        private ProcedureRegistry $procedures,
    ) {}

    public function addMethod(MethodDescriptor $descriptor, callable $handler): void
    {
        $this->procedures->add($descriptor, $handler);
    }

    public function procedure(string $method): RegisteredProcedure
    {
        return $this->procedures->get($method);
    }

    /**
     * @return array<string, RegisteredProcedure>
     */
    public function procedures(): array
    {
        return $this->procedures->all();
    }
}
