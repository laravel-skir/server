<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Codecs\SkirCodec;
use Skir\Server\ProcedureProvider;
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
