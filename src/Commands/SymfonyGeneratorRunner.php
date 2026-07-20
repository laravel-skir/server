<?php

declare(strict_types=1);

namespace Skir\Server\Commands;

use Closure;
use Symfony\Component\Process\Process;

final class SymfonyGeneratorRunner implements GeneratorRunner
{
    public function run(array $command, string $workingDirectory, ?float $timeout, Closure $output): int
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout($timeout);

        return $process->run($output);
    }
}
