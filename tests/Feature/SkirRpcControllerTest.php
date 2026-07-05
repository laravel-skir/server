<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use LaravelSkir\Runtime\DenseJson;
use LaravelSkir\Runtime\Field;
use LaravelSkir\Runtime\MethodDescriptor;
use LaravelSkir\Runtime\Type;
use LaravelSkir\Server\Codecs\SkirCodecs;
use LaravelSkir\Server\ProcedureProvider;
use LaravelSkir\Server\RequestContext;
use LaravelSkir\Server\SkirServer;
use LaravelSkir\Server\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SkirRpcControllerTest extends TestCase
{
    #[Test]
    public function it_dispatches_a_posted_skir_rpc_request(): void
    {
        app(SkirServer::class)->addMethod(
            new MethodDescriptor('Square', 1001, Type::float32(), Type::float32()),
            fn (float $value): float => $value * $value,
        );

        $this
            ->postJson('/skir', [
                'method' => 'Square',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertContent('25');
    }

    #[Test]
    public function it_decodes_struct_requests_and_encodes_struct_responses(): void
    {
        $userType = Type::struct([
            Field::value('id', 0, Type::int32()),
            Field::value('name', 1, Type::string()),
        ]);

        app(SkirServer::class)->addMethod(
            new MethodDescriptor('RenameUser', 1002, $userType, $userType),
            fn (array $user, RequestContext $context): array => [
                'id' => $user['id'],
                'name' => "{$user['name']} from {$context->request->ip()}",
            ],
        );

        $this
            ->postJson('/skir', [
                'method' => 'RenameUser',
                'request' => [42, 'Ruben'],
            ])
            ->assertOk()
            ->assertExactJson([42, 'Ruben from 127.0.0.1']);
    }

    #[Test]
    public function it_dispatches_a_get_request_from_the_query_string(): void
    {
        app(SkirServer::class)->addMethod(
            new MethodDescriptor('Square', 1001, Type::float32(), Type::float32()),
            fn (float $value): float => $value * $value,
        );

        $this
            ->getJson('/skir?method=Square&request=5.0')
            ->assertOk()
            ->assertContent('25');
    }

    #[Test]
    public function it_registers_a_route_macro_for_skir_rpc_endpoints(): void
    {
        $route = Route::skirRpc('/rpc');

        $this->assertInstanceOf(LaravelRoute::class, $route);

        app(SkirServer::class)->addMethod(
            new MethodDescriptor('Square', 1001, Type::float32(), Type::float32()),
            fn (float $value): float => $value * $value,
        );

        $this
            ->postJson('/rpc', [
                'method' => 'Square',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertContent('25');
    }

    #[Test]
    public function it_exposes_endpoint_studio_when_enabled_on_the_route(): void
    {
        Route::skirRpc('/studio-rpc', [
            SquareProcedureProvider::class,
            DoubleProcedureProvider::class,
        ])->studio();

        $this
            ->get('/studio-rpc?studio')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertSee('Skir RPC Studio')
            ->assertSee('Square')
            ->assertSee('Double')
            ->assertSee('/studio-rpc');
    }

    #[Test]
    public function it_keeps_endpoint_studio_disabled_by_default(): void
    {
        Route::skirRpc('/private-studio-rpc', [
            SquareProcedureProvider::class,
        ]);

        $this
            ->get('/private-studio-rpc?studio')
            ->assertNotFound();
    }

    #[Test]
    public function it_registers_procedure_providers_on_a_route(): void
    {
        Route::skirRpc('/provider-rpc', [
            SquareProcedureProvider::class,
        ]);

        $this
            ->postJson('/provider-rpc', [
                'method' => 'Square',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertContent('25');
    }

    #[Test]
    public function it_keeps_route_procedure_registries_isolated(): void
    {
        Route::skirRpc('/square-rpc', [
            SquareProcedureProvider::class,
        ]);

        Route::skirRpc('/double-rpc', [
            DoubleProcedureProvider::class,
        ]);

        $this
            ->postJson('/square-rpc', [
                'method' => 'Double',
                'request' => 5.0,
            ])
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_method_not_found',
                    'message' => 'Skir method [Double] is not registered.',
                ],
            ]);

        $this
            ->postJson('/double-rpc', [
                'method' => 'Double',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertContent('10');
    }

    #[Test]
    public function it_can_use_standard_json_payloads_for_an_endpoint(): void
    {
        Route::skirRpc('/standard-rpc', [
            RenameUserProcedureProvider::class,
        ], SkirCodecs::standardJson());

        $this
            ->postJson('/standard-rpc', [
                'method' => 'RenameUser',
                'request' => [
                    'id' => 42,
                    'name' => 'Ruben',
                ],
            ])
            ->assertOk()
            ->assertExactJson([
                'id' => 42,
                'name' => 'Ruben updated',
            ]);
    }

    #[Test]
    public function it_can_use_base64_encoded_dense_json_payloads_for_an_endpoint(): void
    {
        Route::skirRpc('/base64-rpc', [
            SquareProcedureProvider::class,
        ], SkirCodecs::base64DenseJson());

        $this
            ->postJson('/base64-rpc', [
                'method' => 'Square',
                'request' => base64_encode(DenseJson::toJson(Type::float32(), 5.0)),
            ])
            ->assertOk()
            ->assertContent(json_encode(base64_encode(DenseJson::toJson(Type::float32(), 25.0)), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_returns_a_package_local_error_for_unknown_methods(): void
    {
        $this
            ->postJson('/skir', [
                'method' => 'Missing',
                'request' => [],
            ])
            ->assertNotFound()
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_method_not_found',
                    'message' => 'Skir method [Missing] is not registered.',
                ],
            ]);
    }

    #[Test]
    public function it_returns_a_package_local_error_for_invalid_request_envelopes(): void
    {
        $this
            ->postJson('/skir', [
                'request' => 5.0,
            ])
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_invalid_request',
                    'message' => 'Skir requests must include a string [method] value.',
                ],
            ]);
    }
}

final class SquareProcedureProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('Square', 1001, Type::float32(), Type::float32()),
            fn (float $value): float => $value * $value,
        );
    }
}

final class DoubleProcedureProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('Double', 1002, Type::float32(), Type::float32()),
            fn (float $value): float => $value * 2,
        );
    }
}

final class RenameUserProcedureProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('RenameUser', 1003, Type::struct([
                Field::value('id', 0, Type::int32()),
                Field::value('name', 1, Type::string()),
            ]), Type::struct([
                Field::value('id', 0, Type::int32()),
                Field::value('name', 1, Type::string()),
            ])),
            fn (array $user): array => [
                'id' => $user['id'],
                'name' => "{$user['name']} updated",
            ],
        );
    }
}
