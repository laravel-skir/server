<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use PhpParser\ParserFactory;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Symfony\Component\Process\Process;
use Throwable;

final class PhpSourceValidator
{
    public function __construct(
        private readonly ScaffoldingFilesystem $filesystem,
        private readonly ?string $temporaryDirectory = null,
    ) {}

    public function validate(string $source, string $destinationPath): void
    {
        try {
            (new ParserFactory)->createForHostVersion()->parse($source);
        } catch (Throwable $exception) {
            throw SkirScaffoldingException::invalidRenderedController($destinationPath, $exception);
        }

        $temporaryPath = $this->filesystem->temporaryFile(
            $this->temporaryDirectory ?? sys_get_temp_dir(),
        );

        try {
            $this->filesystem->write($temporaryPath, $source);
            $process = new Process([PHP_BINARY, '-l', $temporaryPath]);

            try {
                $process->run();
            } catch (Throwable $exception) {
                throw SkirScaffoldingException::invalidRenderedController($destinationPath, $exception);
            }

            if (! $process->isSuccessful()) {
                $output = trim($process->getErrorOutput()."\n".$process->getOutput());

                throw SkirScaffoldingException::renderedControllerDoesNotCompile($destinationPath, $output);
            }
        } finally {
            $this->filesystem->remove($temporaryPath);
        }
    }
}
