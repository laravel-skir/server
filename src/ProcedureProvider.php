<?php

declare(strict_types=1);

namespace Skir\Server;

interface ProcedureProvider
{
    public function register(SkirServer $server): void;
}
