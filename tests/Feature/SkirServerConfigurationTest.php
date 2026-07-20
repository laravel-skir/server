<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\MethodDescriptor;
use Skir\Server\Codecs\DenseJsonCodec;
use Skir\Server\Codecs\SkirCodec;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\ProcedureProvider;
use Skir\Server\SkirServer;
use Skir\Server\SkirServerServiceProvider;
use Skir\Server\Tests\TestCase;

final class SkirServerConfigurationTest extends TestCase
{
    #[Test]
    public function studio_is_disabled_by_default(): void
    {
        $this->assertFalse(config('skir-server.studio_enabled'));
    }

    #[Test]
    public function studio_uses_the_studio_query_key_by_default(): void
    {
        $this->assertSame('studio', config('skir-server.studio_query_key'));
    }

    #[Test]
    public function studio_can_be_enabled_by_configuration(): void
    {
        config(['skir-server.studio_enabled' => true]);

        Route::skirRpc('/configured-studio', [
            new ConfigurationProcedureProvider,
        ]);

        $this
            ->get('/configured-studio?studio')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertSee('Skir RPC Studio');
    }

    #[Test]
    public function studio_can_be_disabled_on_a_route_when_enabled_by_configuration(): void
    {
        config(['skir-server.studio_enabled' => true]);

        Route::skirRpc('/disabled-configured-studio', [
            new ConfigurationProcedureProvider,
        ])->studio(false);

        $this
            ->get('/disabled-configured-studio?studio')
            ->assertNotFound();
    }

    #[Test]
    public function studio_query_key_can_be_configured(): void
    {
        config([
            'skir-server.studio_enabled' => true,
            'skir-server.studio_query_key' => 'skir-studio',
        ]);

        Route::skirRpc('/configured-studio-query-key', [
            new ConfigurationProcedureProvider,
        ]);

        $this
            ->get('/configured-studio-query-key?studio')
            ->assertUnprocessable()
            ->assertDontSee('Skir RPC Studio');

        $this
            ->get('/configured-studio-query-key?skir-studio')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertSee('Skir RPC Studio');
    }

    #[Test]
    public function dense_json_is_the_default_codec(): void
    {
        $this->assertSame(DenseJsonCodec::class, config('skir-server.codec'));
    }

    #[Test]
    public function the_configured_codec_is_used_by_global_and_isolated_servers(): void
    {
        config(['skir-server.codec' => ConfiguredCodec::class]);

        $globalServer = app(SkirServer::class);
        $route = Route::skirRpc('/configured-codec', [
            new ConfigurationProcedureProvider,
        ]);
        $isolatedServer = $this->serverFromRoute($route);
        $configuredCodec = $globalServer->codec();

        $this->assertInstanceOf(ConfiguredCodec::class, $configuredCodec);
        $this->assertInstanceOf(ConfiguredCodecDependency::class, $configuredCodec->dependency);
        $this->assertSame($configuredCodec, $isolatedServer->codec());
    }

    #[Test]
    public function configured_codec_construction_exceptions_are_preserved(): void
    {
        config(['skir-server.codec' => FailingConfiguredCodec::class]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Configured codec construction failed.');

        app(SkirCodec::class);
    }

    #[Test]
    public function an_explicit_route_codec_overrides_the_configured_codec(): void
    {
        config(['skir-server.codec' => InvalidConfiguredCodec::class]);

        $explicitCodec = new ExplicitCodec;
        $route = Route::skirRpc(
            '/explicit-codec',
            [new ConfigurationProcedureProvider],
            $explicitCodec,
        );

        $this->assertSame($explicitCodec, $this->serverFromRoute($route)->codec());
    }

    #[Test]
    public function an_invalid_configured_codec_is_rejected(): void
    {
        config(['skir-server.codec' => InvalidConfiguredCodec::class]);

        $this->expectException(SkirServerException::class);
        $this->expectExceptionMessage(
            'Configured Skir codec ['.InvalidConfiguredCodec::class.'] must implement '
            .'[Skir\\Server\\Codecs\\SkirCodec].',
        );

        app(SkirServer::class);
    }

    #[Test]
    public function a_non_string_configured_codec_is_rejected(): void
    {
        config(['skir-server.codec' => []]);

        $this->expectException(SkirServerException::class);
        $this->expectExceptionMessage(
            'Configured Skir codec [array] must implement [Skir\\Server\\Codecs\\SkirCodec].',
        );

        app(SkirCodec::class);
    }

    #[Test]
    public function package_configuration_is_publishable(): void
    {
        $publishablePaths = ServiceProvider::pathsToPublish(
            SkirServerServiceProvider::class,
            'skir-server-config',
        );

        $this->assertCount(1, $publishablePaths);
        $this->assertSame([config_path('skir-server.php')], array_values($publishablePaths));

        $sourcePath = array_key_first($publishablePaths);

        $this->assertIsString($sourcePath);
        $this->assertFileExists($sourcePath);
    }

    private function serverFromRoute(LaravelRoute $route): SkirServer
    {
        $server = $route->defaults['skirServer'] ?? null;

        $this->assertInstanceOf(SkirServer::class, $server);

        return $server;
    }
}

final readonly class ConfiguredCodec implements SkirCodec
{
    public function __construct(public ConfiguredCodecDependency $dependency) {}

    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed
    {
        return $request;
    }

    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): mixed
    {
        return $response;
    }
}

final class ConfiguredCodecDependency {}

final class FailingConfiguredCodec implements SkirCodec
{
    public function __construct()
    {
        throw new LogicException('Configured codec construction failed.');
    }

    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed
    {
        return $request;
    }

    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): mixed
    {
        return $response;
    }
}

final class ExplicitCodec implements SkirCodec
{
    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed
    {
        return $request;
    }

    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): mixed
    {
        return $response;
    }
}

final class InvalidConfiguredCodec {}

final class ConfigurationProcedureProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void {}
}
