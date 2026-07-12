<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;

final readonly class RenderedController
{
    /**
     * @param  list<SkirMethodDefinition>  $methods
     * @param  list<string>  $routeHints
     */
    public function __construct(
        public RenderedFile $file,
        public string $className,
        public array $methods,
        public array $routeHints,
    ) {}
}
