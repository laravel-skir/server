<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding\Manifest;

final readonly class SkirMethodDefinition
{
    public function __construct(
        public string $module,
        public string $name,
        public string $enumClass,
        public string $enumCase,
        public string $phpMethod,
        public string $requestType,
        public ?string $requestClass,
        public string $responseType,
        public ?string $responseClass,
    ) {}

    public function id(): string
    {
        return "{$this->module}.{$this->name}";
    }
}
