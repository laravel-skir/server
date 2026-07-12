<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use Skir\Server\Facades\Skir;

final readonly class RouteRegistration
{
    public function __construct(
        public string $controllerClass,
        public ?string $enumClass = null,
        public ?string $enumCase = null,
    ) {}

    public function snippet(): string
    {
        $facadeClass = '\\'.Skir::class;
        $controllerClass = '\\'.ltrim($this->controllerClass, '\\');

        if ($this->enumClass === null || $this->enumCase === null) {
            return "{$facadeClass}::controller({$controllerClass}::class)";
        }

        $enumClass = '\\'.ltrim($this->enumClass, '\\');

        return "{$facadeClass}::method({$enumClass}::{$this->enumCase}, {$controllerClass}::class)";
    }
}
