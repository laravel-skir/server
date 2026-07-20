<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use Closure;
use Skir\Server\Exceptions\SkirScaffoldingException;

final class AtomicFilePublisher
{
    /** @param (Closure(string, string): bool)|null $linker */
    public function __construct(
        private readonly ScaffoldingFilesystem $filesystem,
        private readonly ?Closure $linker = null,
    ) {}

    /**
     * @param  Closure(string): SkirScaffoldingException  $collisionException
     * @param  Closure(string): SkirScaffoldingException  $unavailableException
     */
    public function publish(
        string $temporaryPath,
        string $destinationPath,
        Closure $collisionException,
        Closure $unavailableException,
    ): void {
        if ($this->link($temporaryPath, $destinationPath)) {
            return;
        }

        if ($this->filesystem->exists($destinationPath)) {
            throw $collisionException($destinationPath);
        }

        throw $unavailableException($destinationPath);
    }

    private function link(string $temporaryPath, string $destinationPath): bool
    {
        if ($this->linker !== null) {
            return ($this->linker)($temporaryPath, $destinationPath);
        }

        return @link($temporaryPath, $destinationPath);
    }
}
