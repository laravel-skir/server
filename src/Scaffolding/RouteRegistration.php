<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

final readonly class RouteRegistration
{
    public function __construct(
        public string $controllerClass,
        public ?string $enumClass = null,
        public ?string $enumCase = null,
    ) {}

    public function snippet(): string
    {
        $controllerClass = '\\'.ltrim($this->controllerClass, '\\');

        if ($this->enumClass === null || $this->enumCase === null) {
            return "Skir::controller({$controllerClass}::class)";
        }

        $enumClass = '\\'.ltrim($this->enumClass, '\\');

        return "Skir::method({$enumClass}::{$this->enumCase}, {$controllerClass}::class)";
    }
}
