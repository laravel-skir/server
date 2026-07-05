<?php

declare(strict_types=1);

namespace LaravelSkir\Server;

interface ProcedureProvider
{
    public function register(SkirServer $server): void;
}
