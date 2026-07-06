<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Facades;

use LaravelSkir\Server\Contracts\SkirMethodReference;
use LaravelSkir\Server\Routing\SkirControllerRouteDefinition;
use LaravelSkir\Server\Routing\SkirMethodRouteDefinition;

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
