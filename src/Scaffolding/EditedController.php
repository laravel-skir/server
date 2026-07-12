<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

final readonly class EditedController
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $source,
        public bool $changed,
        public array $warnings,
    ) {}
}
