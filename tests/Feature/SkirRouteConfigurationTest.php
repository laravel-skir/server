<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Container\Attributes\Bind;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Codecs\SkirCodec;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\ProcedureProvider;
use Skir\Server\Routing\SkirRouteDefinition;
use Skir\Server\SkirServer;
use Skir\Server\Tests\TestCase;

final class SkirRouteConfigurationTest extends TestCase
{
    #[Test]
    public function it_resolves_procedure_provider_dependencies_from_the_container(): void
    {
        Route::skirRpc('/provider-dependency', [
            ContainerResolvedProvider::class,
        ]);

        $this
            ->postJson('/provider-dependency', [
                'method' => 'ProviderDependency',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertContent('"provider:5"');
    }

    #[Test]
    public function it_rejects_invalid_configured_route_providers(): void
    {
        $this->expectException(SkirServerException::class);
        $this->expectExceptionMessage(
            'Skir route provider ['.InvalidConfiguredProvider::class.'] must implement ['
            .'Skir\\Server\\Routing\\SkirRouteDefinition] or [Skir\\Server\\ProcedureProvider].',
        );

        Route::skirRpc('/invalid-provider', [
            InvalidConfiguredProvider::class,
        ]);
    }

    #[Test]
    public function it_rejects_missing_configured_route_provider_classes_with_the_configured_identity(): void
    {
        $this->expectException(SkirServerException::class);
        $this->expectExceptionMessage(
            'Skir route provider ['.MissingConfiguredProvider::class.'] must implement ['
            .'Skir\\Server\\Routing\\SkirRouteDefinition] or [Skir\\Server\\ProcedureProvider].',
        );

        Route::skirRpc('/missing-provider', [
            MissingConfiguredProvider::class,
        ]);
    }

    #[Test]
    public function it_does_not_mask_errors_from_valid_provider_factories(): void
    {
        app()->bind(
            FactoryFailureProvider::class,
            fn (): FactoryFailureProvider => throw new LogicException('Provider factory failed.'),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Provider factory failed.');

        Route::skirRpc('/factory-failure', [
            FactoryFailureProvider::class,
        ]);
    }

    #[Test]
    public function it_does_not_mask_binding_resolution_errors_from_bound_provider_factories(): void
    {
        app()->bind(
            BindingResolutionFactoryProvider::class,
            fn (): BindingResolutionFactoryProvider => throw new BindingResolutionException('Bound provider resolution failed.'),
        );

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Bound provider resolution failed.');

        Route::skirRpc('/binding-resolution-factory-failure', [
            BindingResolutionFactoryProvider::class,
        ]);
    }

    #[Test]
    public function it_resolves_container_bound_provider_interfaces(): void
    {
        app()->bind(ContainerBoundProvider::class, ContainerBoundProviderImplementation::class);

        $route = Route::skirRpc('/bound-provider', [
            ContainerBoundProvider::class,
        ]);

        $this->assertSame(
            ['ContainerBoundMethod'],
            array_keys($this->serverFromRoute($route)->procedures()),
        );
    }

    #[Test]
    public function it_resolves_lazily_bound_provider_interfaces(): void
    {
        app()->resolveEnvironmentUsing(app()->environment(...));

        $route = Route::skirRpc('/lazy-bound-provider', [
            LazyBoundProvider::class,
        ]);

        $this->assertSame(
            ['LazyBoundMethod'],
            array_keys($this->serverFromRoute($route)->procedures()),
        );
    }

    #[Test]
    public function it_does_not_mask_binding_resolution_errors_from_concrete_providers(): void
    {
        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Target [Skir\\Server\\Tests\\Feature\\MissingProviderDependency] is not instantiable');

        Route::skirRpc('/constructor-resolution-failure', [
            ConstructorResolutionFailureProvider::class,
        ]);
    }

    #[Test]
    public function it_preserves_the_order_of_mixed_route_definitions_and_procedure_providers(): void
    {
        $route = Route::skirRpc('/mixed-providers', [
            new OrderedRouteDefinition,
            new OrderedProcedureProvider,
        ]);

        $this->assertSame(
            ['RouteDefinitionMethod', 'ProcedureProviderMethod'],
            array_keys($this->serverFromRoute($route)->procedures()),
        );
    }

    #[Test]
    public function a_custom_codec_with_no_providers_creates_an_isolated_server(): void
    {
        app(SkirServer::class)->addMethod(
            new MethodDescriptor('GlobalMethod', 5001, Type::string(), Type::string()),
            fn (string $request): string => $request,
        );

        $route = Route::skirRpc('/custom-codec', [], new MarkerCodec);
        $server = $this->serverFromRoute($route);
        $server->addMethod(
            new MethodDescriptor('RouteMethod', 5002, Type::string(), Type::string()),
            fn (string $request): string => $request,
        );

        $this
            ->postJson('/custom-codec', [
                'method' => 'RouteMethod',
                'request' => 'value',
            ])
            ->assertOk()
            ->assertExactJson(['encoded' => 'value']);

        $this
            ->postJson('/custom-codec', [
                'method' => 'GlobalMethod',
                'request' => 'value',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function route_specific_codecs_do_not_leak_between_endpoints(): void
    {
        Route::skirRpc('/first-codec', [new RouteEchoProvider], new MarkerCodec('first'));
        Route::skirRpc('/second-codec', [new RouteEchoProvider], new MarkerCodec('second'));

        $this
            ->postJson('/first-codec', [
                'method' => 'Echo',
                'request' => 'value',
            ])
            ->assertExactJson(['encoded' => 'first:value']);

        $this
            ->postJson('/second-codec', [
                'method' => 'Echo',
                'request' => 'value',
            ])
            ->assertExactJson(['encoded' => 'second:value']);
    }

    #[Test]
    public function studio_can_be_explicitly_disabled(): void
    {
        Route::skirRpc('/explicitly-disabled-studio', [
            new RouteEchoProvider,
        ])->studio(false);

        $this
            ->get('/explicitly-disabled-studio?studio')
            ->assertNotFound();
    }

    private function serverFromRoute(LaravelRoute $route): SkirServer
    {
        $server = $route->defaults['skirServer'] ?? null;

        $this->assertInstanceOf(SkirServer::class, $server);

        return $server;
    }
}

final class ContainerResolvedProvider implements ProcedureProvider
{
    public function __construct(
        private readonly ProviderDependency $dependency,
    ) {}

    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('ProviderDependency', 5003, Type::float32(), Type::string()),
            fn (float $request): string => $this->dependency->describe($request),
        );
    }
}

final class ProviderDependency
{
    public function describe(float $value): string
    {
        return "provider:{$value}";
    }
}

final class InvalidConfiguredProvider {}

final class FactoryFailureProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void {}
}

final class BindingResolutionFactoryProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void {}
}

interface ContainerBoundProvider extends ProcedureProvider {}

final class ContainerBoundProviderImplementation implements ContainerBoundProvider
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('ContainerBoundMethod', 5007, Type::string(), Type::string()),
            fn (string $request): string => $request,
        );
    }
}

#[Bind(LazyBoundProviderImplementation::class)]
interface LazyBoundProvider extends ProcedureProvider {}

final class LazyBoundProviderImplementation implements LazyBoundProvider
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('LazyBoundMethod', 5008, Type::string(), Type::string()),
            fn (string $request): string => $request,
        );
    }
}

interface MissingProviderDependency {}

final class ConstructorResolutionFailureProvider implements ProcedureProvider
{
    public function __construct(MissingProviderDependency $dependency) {}

    public function register(SkirServer $server): void {}
}

final class OrderedRouteDefinition implements SkirRouteDefinition
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('RouteDefinitionMethod', 5005, Type::string(), Type::string()),
            fn (string $request): string => $request,
        );
    }
}

final class OrderedProcedureProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('ProcedureProviderMethod', 5006, Type::string(), Type::string()),
            fn (string $request): string => $request,
        );
    }
}

final class RouteEchoProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('Echo', 5004, Type::string(), Type::string()),
            fn (string $request): string => $request,
        );
    }
}

final readonly class MarkerCodec implements SkirCodec
{
    public function __construct(
        private string $prefix = '',
    ) {}

    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed
    {
        return $request;
    }

    /**
     * @return array{encoded: mixed}
     */
    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): array
    {
        $encoded = $this->prefix === '' ? $response : "{$this->prefix}:{$response}";

        return ['encoded' => $encoded];
    }
}
