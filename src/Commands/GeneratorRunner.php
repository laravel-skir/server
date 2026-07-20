<?php

declare(strict_types=1);

namespace Skir\Server\Commands;

use Closure;

interface GeneratorRunner
{
    /** @param list<string> $command */
    public function run(array $command, string $workingDirectory, ?float $timeout, Closure $output): int;
}
