<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

final readonly class ControllerReplacement
{
    public function __construct(
        public string $destinationPath,
        public string $backupPath,
        public FileSnapshot $published,
    ) {}
}
