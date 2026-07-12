<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;

final readonly class RenderedController
{
    /**
     * @param  list<SkirMethodDefinition>  $methods
     * @param  list<RouteRegistration>  $registrations
     * @param  list<string>  $warnings
     */
    public function __construct(
        public RenderedFile $file,
        public string $className,
        public array $methods,
        public array $registrations,
        public bool $existing = false,
        public bool $changed = true,
        public ?string $originalSource = null,
        public array $warnings = [],
    ) {}
}
