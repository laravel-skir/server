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
use Skir\Server\Commands\GeneratorRunner;
use Skir\Server\Commands\MakeSkirCommand;
use Skir\Server\Commands\MakeSkirRequestCommand;
use Skir\Server\Commands\SymfonyGeneratorRunner;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\Http\Controllers\SkirRpcController;
use Skir\Server\Routing\SkirRouteDefinition;
use Skir\Server\Scaffolding\ControllerScaffolder;
use Skir\Server\Scaffolding\ControllerScaffolding;
use Skir\Server\Scaffolding\Manifest\ManifestRepository;

final class SkirServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configurationPath = __DIR__.'/../config/skir-server.php';

        $this->mergeConfigFrom($configurationPath, 'skir-server');

        $defaults = require $configurationPath;
        $configuredScaffolding = config('skir-server.scaffolding', []);
        $configuredScaffolding = is_array($configuredScaffolding) ? $configuredScaffolding : [];

        config()->set('skir-server.scaffolding', array_replace(
            $defaults['scaffolding'],
            $configuredScaffolding,
        ));

        $this->app->singleton(ProcedureRegistry::class);
        $this->app->singleton(SkirCodec::class, function (): SkirCodec {
            $codec = config('skir-server.codec', DenseJsonCodec::class);

            if (! is_string($codec)) {
                throw SkirServerException::invalidConfiguredCodec($codec);
            }

            if (! is_a($codec, SkirCodec::class, true)) {
                throw SkirServerException::invalidConfiguredCodec($codec);
            }

            return $this->app->make($codec);
        });
        $this->app->singleton(SkirServer::class);
        $this->app->singleton(ManifestRepository::class);
        $this->app->bind(GeneratorRunner::class, SymfonyGeneratorRunner::class);
        $this->app->bind(ControllerScaffolding::class, ControllerScaffolder::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/skir-server.php' => config_path('skir-server.php'),
        ], 'skir-server-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeSkirCommand::class,
                MakeSkirRequestCommand::class,
            ]);
        }

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
            $route = $this
                ->match(['GET', 'POST'], $uri, SkirRpcController::class)
                ->defaults('skirStudioEnabled', (bool) config('skir-server.studio_enabled', false))
                ->defaults('skirStudioQueryKey', (string) config('skir-server.studio_query_key', 'studio'));

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
