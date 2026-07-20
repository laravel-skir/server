<?php

declare(strict_types=1);

namespace Skir\Server;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Skir\Server\Codecs\DenseJsonCodec;
use Skir\Server\Codecs\SkirCodec;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\Http\Controllers\SkirRpcController;
use Skir\Server\Routing\SkirRouteDefinition;

final class SkirServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/skir-server.php', 'skir-server');

        $this->app->singleton(ProcedureRegistry::class);
        $this->app->singleton(SkirCodec::class, DenseJsonCodec::class);
        $this->app->singleton(SkirServer::class);
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
        $application = $this->app;
        $resolveProvider = static function (mixed $provider) use ($application): mixed {
            if (! is_string($provider)) {
                return $provider;
            }

            try {
                return $application->make($provider);
            } catch (BindingResolutionException $exception) {
                if ($application->bound($provider)) {
                    throw $exception;
                }

                if (class_exists($provider)) {
                    if ((new ReflectionClass($provider))->isInstantiable()) {
                        throw $exception;
                    }
                }

                throw SkirServerException::invalidRouteProvider($provider);
            }
        };

        $router->macro('skirRpc', function (string $uri, array $providers = [], ?SkirCodec $codec = null) use ($resolveProvider) {
            /** @var Router $this */
            $route = $this->match(['GET', 'POST'], $uri, SkirRpcController::class);

            if ($providers === []) {
                if ($codec === null) {
                    return $route;
                }
            }

            $server = new SkirServer(new ProcedureRegistry, $codec ?? app(SkirCodec::class));

            foreach ($providers as $provider) {
                $resolvedProvider = $resolveProvider($provider);

                if ($resolvedProvider instanceof SkirRouteDefinition) {
                    $resolvedProvider->register($server);

                    continue;
                }

                if (! $resolvedProvider instanceof ProcedureProvider) {
                    throw SkirServerException::invalidRouteProvider($resolvedProvider);
                }

                $resolvedProvider->register($server);
            }

            return $route->defaults('skirServer', $server);
        });
    }
}
