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

    public function snapshot(string $path): ?FileSnapshot
    {
        $snapshot = $this->run('snapshot', $path, static function () use ($path): ?FileSnapshot {
            if (! is_file($path)) {
                return null;
            }

            $handle = @fopen($path, 'rb');

            if (! is_resource($handle)) {
                return null;
            }

            try {
                if (! flock($handle, LOCK_SH)) {
                    return null;
                }

                $openedFile = fstat($handle);
                rewind($handle);
                $contents = stream_get_contents($handle);
                clearstatcache(true, $path);
                $currentPath = @lstat($path);

                if (! is_array($openedFile) || ! is_array($currentPath) || ! is_string($contents)) {
                    return null;
                }

                if ($openedFile['dev'] !== $currentPath['dev'] || $openedFile['ino'] !== $currentPath['ino']) {
                    return null;
                }

                return new FileSnapshot(
                    $contents,
                    (int) $openedFile['dev'],
                    (int) $openedFile['ino'],
                    (int) $openedFile['mode'] & 07777,
                );
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        });

        return $snapshot instanceof FileSnapshot ? $snapshot : null;
    }

    public function displaceToBackup(string $destinationPath): string
    {
        $backupPath = $this->temporaryFile(dirname($destinationPath));
        $this->remove($backupPath);
        $moved = $this->run(
            'displaceToBackup',
            $destinationPath,
            static fn (): bool => rename($destinationPath, $backupPath),
        );

        if ($moved !== true) {
            throw SkirScaffoldingException::filesystemOperationFailed('displaceToBackup', $destinationPath);
        }

        return $backupPath;
    }

    public function restoreBackup(string $backupPath, string $destinationPath): bool
    {
        $restored = $this->run('restoreBackup', $destinationPath, static function () use ($backupPath, $destinationPath): bool {
            if (file_exists($destinationPath) || is_link($destinationPath)) {
                return false;
            }

            return link($backupPath, $destinationPath);
        });

        if ($restored !== true) {
            return false;
        }

        $this->remove($backupPath);

        return true;
    }

    /**
     * Atomically removes the path only while it still matches the snapshotted inode, contents, and mode.
     *
     * Advisory locking also protects cooperative in-place writers. Non-cooperating in-place writes
     * cannot be made fully transactional by portable PHP filesystem APIs.
     */
    public function removeIfUnchanged(string $path, FileSnapshot $expected): bool
    {
        $removed = $this->run('removeIfUnchanged', $path, static function () use ($path, $expected): bool {
            $handle = @fopen($path, 'r+b');

            if (! is_resource($handle)) {
                return false;
            }

            try {
                if (! flock($handle, LOCK_EX)) {
                    return false;
                }

                $openedFile = fstat($handle);
                rewind($handle);
                $contents = stream_get_contents($handle);
                clearstatcache(true, $path);
                $currentPath = @lstat($path);

                if (! is_array($openedFile) || ! is_array($currentPath)) {
                    return false;
                }

                if ($contents !== $expected->contents) {
                    return false;
                }

                if ((int) $openedFile['dev'] !== $expected->device || (int) $openedFile['ino'] !== $expected->inode) {
                    return false;
                }

                if (((int) $openedFile['mode'] & 07777) !== $expected->mode) {
                    return false;
                }

                if ($openedFile['dev'] !== $currentPath['dev'] || $openedFile['ino'] !== $currentPath['ino']) {
                    return false;
                }

                return unlink($path);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        });

        return $removed === true;
    }

    public function removeDirectoryIfEmpty(string $path): void
    {
        if (! $this->isDirectory($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries !== ['.', '..']) {
            return;
        }

        $this->run('removeDirectory', $path, static fn (): bool => rmdir($path));
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
