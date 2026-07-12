<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

final readonly class RenderedFile
{
    public function __construct(
        public string $destinationPath,
        public string $source,
    ) {}
}
