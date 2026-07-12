<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

final readonly class ControllerScaffoldingResult
{
    /**
     * @param  list<string>  $createdPaths
     * @param  list<string>  $unchangedPaths
     * @param  list<string>  $routeHints
     */
    public function __construct(
        public array $createdPaths,
        public array $unchangedPaths,
        public array $routeHints,
    ) {}
}
