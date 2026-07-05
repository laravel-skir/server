<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Tests;

use Illuminate\Contracts\Routing\Registrar;
use LaravelSkir\Server\Http\Controllers\SkirRpcController;
use LaravelSkir\Server\SkirServerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SkirServerServiceProvider::class,
        ];
    }

    protected function defineRoutes($router): void
    {
        /** @var Registrar $router */
        $router->match(['GET', 'POST'], '/skir', SkirRpcController::class);
    }
}
