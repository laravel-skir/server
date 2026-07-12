<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use Closure;
use ErrorException;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Throwable;

final class ScaffoldingFilesystem
{
    /** @param (Closure(string, string): void)|null $beforeOperation */
    public function __construct(private readonly ?Closure $beforeOperation = null) {}

    public function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function read(string $path): string
    {
        $contents = $this->run('read', $path, static fn (): string|false => file_get_contents($path));

        if (! is_string($contents)) {
            throw SkirScaffoldingException::filesystemOperationFailed('read', $path);
        }

        return $contents;
    }

    public function makeDirectory(string $path): void
    {
        try {
            $created = $this->run(
                'makeDirectory',
                $path,
                static fn (): bool => mkdir($path, 0777, true),
            );
        } catch (SkirScaffoldingException $exception) {
            if ($this->isDirectory($path)) {
                return;
            }

            throw $exception;
        }

        if ($created !== true && ! is_dir($path)) {
            throw SkirScaffoldingException::filesystemOperationFailed('makeDirectory', $path);
        }
    }

    public function temporaryFile(string $directory): string
    {
        $path = $this->run(
            'temporaryFile',
            $directory,
            static fn (): string|false => tempnam($directory, '.skir-'),
        );

        if (! is_string($path)) {
            throw SkirScaffoldingException::filesystemOperationFailed('temporaryFile', $directory);
        }

        return $path;
    }

    public function write(string $path, string $contents): void
    {
        $writtenBytes = $this->run(
            'write',
            $path,
            static fn (): int|false => file_put_contents($path, $contents, LOCK_EX),
        );

        if ($writtenBytes !== strlen($contents)) {
            throw SkirScaffoldingException::filesystemOperationFailed('write', $path);
        }
    }

    public function chmod(string $path, int $permissions): void
    {
        $changed = $this->run(
            'chmod',
            $path,
            static fn (): bool => chmod($path, $permissions),
        );

        if ($changed !== true) {
            throw SkirScaffoldingException::filesystemOperationFailed('chmod', $path);
        }
    }

    public function remove(string $path): void
    {
        if (! $this->exists($path)) {
            return;
        }

        try {
            $removed = $this->run('remove', $path, static fn (): bool => unlink($path));
        } catch (SkirScaffoldingException $exception) {
            if (! $this->exists($path)) {
                return;
            }

            throw $exception;
        }

        if ($removed !== true) {
            throw SkirScaffoldingException::filesystemOperationFailed('remove', $path);
        }
    }

    public function replace(string $sourcePath, string $destinationPath): void
    {
        $replaced = $this->run(
            'replace',
            $destinationPath,
            static fn (): bool => rename($sourcePath, $destinationPath),
        );

        if ($replaced !== true) {
            throw SkirScaffoldingException::filesystemOperationFailed('replace', $destinationPath);
        }
    }

    private function run(string $operation, string $path, Closure $callback): mixed
    {
        try {
            if ($this->beforeOperation !== null) {
                ($this->beforeOperation)($operation, $path);
            }

            set_error_handler(static function (
                int $severity,
                string $message,
                string $file,
                int $line,
            ): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            });

            try {
                return $callback();
            } finally {
                restore_error_handler();
            }
        } catch (Throwable $exception) {
            throw SkirScaffoldingException::filesystemOperationFailed($operation, $path, $exception);
        }
    }
}
