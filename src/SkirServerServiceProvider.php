<?php

declare(strict_types=1);

namespace LaravelSkir\Server;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LaravelSkir\Server\Http\Controllers\SkirRpcController;

final class SkirServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProcedureRegistry::class);
        $this->app->singleton(SkirServer::class);
    }

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        $router->macro('skirRpc', function (string $uri) {
            /** @var Router $this */
            return $this->match(['GET', 'POST'], $uri, SkirRpcController::class);
        });
    }
}
