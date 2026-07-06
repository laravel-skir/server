<?php

declare(strict_types=1);

namespace Skir\Server\Facades;

use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\Routing\SkirControllerRouteDefinition;
use Skir\Server\Routing\SkirMethodRouteDefinition;

final class Skir
{
    /**
     * @param  class-string  $controller
     */
    public static function method(SkirMethodReference $method, string $controller): SkirMethodRouteDefinition
    {
        return new SkirMethodRouteDefinition($method, $controller);
    }

    /**
     * @param  class-string  $controller
     */
    public static function controller(string $controller): SkirControllerRouteDefinition
    {
        return new SkirControllerRouteDefinition($controller);
    }
}
