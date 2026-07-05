<?php

declare(strict_types=1);

namespace LaravelSkir\Server;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LaravelSkir\Server\Codecs\DenseJsonCodec;
use LaravelSkir\Server\Codecs\SkirCodec;
use LaravelSkir\Server\Http\Controllers\SkirRpcController;

final class SkirServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProcedureRegistry::class);
        $this->app->singleton(SkirCodec::class, DenseJsonCodec::class);
        $this->app->singleton(SkirServer::class);
    }

    public function boot(): void
    {
        LaravelRoute::macro('studio', function (bool $enabled = true) {
            /** @var LaravelRoute $this */
            return $this->defaults('skirStudioEnabled', $enabled);
        });

        /** @var Router $router */
        $router = $this->app['router'];

        $router->macro('skirRpc', function (string $uri, array $providers = [], ?SkirCodec $codec = null) {
            /** @var Router $this */
            $route = $this->match(['GET', 'POST'], $uri, SkirRpcController::class);

            if ($providers === []) {
                if ($codec === null) {
                    return $route;
                }
            }

            $server = new SkirServer(new ProcedureRegistry, $codec ?? app(SkirCodec::class));

            foreach ($providers as $provider) {
                $resolvedProvider = is_string($provider) ? app($provider) : $provider;

                if (! $resolvedProvider instanceof ProcedureProvider) {
                    continue;
                }

                $resolvedProvider->register($server);
            }

            return $route->defaults('skirServer', $server);
        });
    }
}
