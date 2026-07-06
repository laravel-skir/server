<?php

declare(strict_types=1);

namespace Skir\Server\Tests;

use Illuminate\Contracts\Routing\Registrar;
use Orchestra\Testbench\TestCase as Orchestra;
use Skir\Server\Http\Controllers\SkirRpcController;
use Skir\Server\SkirServerServiceProvider;

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
