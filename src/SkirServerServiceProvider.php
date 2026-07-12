<?php

declare(strict_types=1);

namespace Skir\Server;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Skir\Server\Codecs\DenseJsonCodec;
use Skir\Server\Codecs\SkirCodec;
use Skir\Server\Http\Controllers\SkirRpcController;
use Skir\Server\Routing\SkirRouteDefinition;
use Skir\Server\Scaffolding\Manifest\ManifestRepository;

final class SkirServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/skir-server.php', 'skir-server');

        $this->app->singleton(ProcedureRegistry::class);
        $this->app->singleton(SkirCodec::class, DenseJsonCodec::class);
        $this->app->singleton(SkirServer::class);
        $this->app->singleton(ManifestRepository::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/skir-server.php' => config_path('skir-server.php'),
        ], 'skir-server-config');

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

                if ($resolvedProvider instanceof SkirRouteDefinition) {
                    $resolvedProvider->register($server);

                    continue;
                }

                if (! $resolvedProvider instanceof ProcedureProvider) {
                    continue;
                }

                $resolvedProvider->register($server);
            }

            return $route->defaults('skirServer', $server);
        });
    }
}
