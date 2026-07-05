<?php

declare(strict_types=1);

namespace LaravelSkir\Server;

use LaravelSkir\Runtime\MethodDescriptor;
use LaravelSkir\Server\Codecs\DenseJsonCodec;
use LaravelSkir\Server\Codecs\SkirCodec;

final readonly class SkirServer
{
    public function __construct(
        private ProcedureRegistry $procedures,
        private SkirCodec $codec = new DenseJsonCodec,
    ) {}

    public function addMethod(MethodDescriptor $descriptor, callable $handler): void
    {
        $this->procedures->add($descriptor, $handler);
    }

    public function procedure(string $method): RegisteredProcedure
    {
        return $this->procedures->get($method);
    }

    public function codec(): SkirCodec
    {
        return $this->codec;
    }

    /**
     * @return array<string, RegisteredProcedure>
     */
    public function procedures(): array
    {
        return $this->procedures->all();
    }
}
