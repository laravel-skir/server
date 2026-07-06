<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

use Skir\Server\SkirServer;

interface SkirRouteDefinition
{
    public function register(SkirServer $server): void;
}
