<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Routing;

use LaravelSkir\Server\SkirServer;

interface SkirRouteDefinition
{
    public function register(SkirServer $server): void;
}
