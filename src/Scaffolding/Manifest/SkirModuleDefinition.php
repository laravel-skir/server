<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding\Manifest;

final readonly class SkirModuleDefinition
{
    /** @param list<SkirMethodDefinition> $methods */
    public function __construct(
        public string $name,
        public string $enumClass,
        public array $methods,
    ) {}
}
