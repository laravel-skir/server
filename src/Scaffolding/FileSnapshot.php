<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

final readonly class FileSnapshot
{
    public function __construct(
        public string $contents,
        public int $device,
        public int $inode,
        public int $mode,
    ) {}

    public function matches(self $other): bool
    {
        return $this->device === $other->device
            && $this->inode === $other->inode
            && $this->contents === $other->contents
            && $this->mode === $other->mode;
    }
}
