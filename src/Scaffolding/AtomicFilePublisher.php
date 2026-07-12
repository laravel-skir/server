<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use Closure;
use Skir\Server\Exceptions\SkirScaffoldingException;

final class AtomicFilePublisher
{
    /** @param (Closure(string, string): bool)|null $linker */
    public function __construct(private readonly ?Closure $linker = null) {}

    public function publish(string $temporaryPath, string $destinationPath): void
    {
        try {
            if ($this->link($temporaryPath, $destinationPath)) {
                return;
            }

            if (file_exists($destinationPath)) {
                throw SkirScaffoldingException::existingFile($destinationPath);
            }

            throw SkirScaffoldingException::atomicPublicationUnavailable($destinationPath);
        } finally {
            if (file_exists($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function link(string $temporaryPath, string $destinationPath): bool
    {
        if ($this->linker !== null) {
            return ($this->linker)($temporaryPath, $destinationPath);
        }

        return @link($temporaryPath, $destinationPath);
    }
}
