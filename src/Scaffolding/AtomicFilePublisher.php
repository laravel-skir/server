<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use Skir\Server\Exceptions\SkirScaffoldingException;

final class AtomicFilePublisher
{
    public function publish(string $temporaryPath, string $destinationPath): void
    {
        try {
            if (@link($temporaryPath, $destinationPath)) {
                return;
            }

            if (file_exists($destinationPath)) {
                throw SkirScaffoldingException::existingFile($destinationPath);
            }

            $this->publishWithExclusiveCreate($temporaryPath, $destinationPath);
        } finally {
            if (file_exists($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function publishWithExclusiveCreate(string $temporaryPath, string $destinationPath): void
    {
        $destination = @fopen($destinationPath, 'xb');

        if ($destination === false) {
            if (file_exists($destinationPath)) {
                throw SkirScaffoldingException::existingFile($destinationPath);
            }

            throw SkirScaffoldingException::unableToWriteFile($destinationPath);
        }

        $source = @fopen($temporaryPath, 'rb');
        $published = false;

        try {
            if ($source === false) {
                throw SkirScaffoldingException::unableToWriteFile($destinationPath);
            }

            $copiedBytes = stream_copy_to_stream($source, $destination);
            $expectedBytes = filesize($temporaryPath);

            if ($expectedBytes === false || $copiedBytes !== $expectedBytes) {
                throw SkirScaffoldingException::unableToWriteFile($destinationPath);
            }

            if (! fflush($destination)) {
                throw SkirScaffoldingException::unableToWriteFile($destinationPath);
            }

            $published = true;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            fclose($destination);

            if (! $published) {
                @unlink($destinationPath);
            }
        }
    }
}
