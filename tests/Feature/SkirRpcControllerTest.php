<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Tests\Feature;

use Illuminate\Support\Facades\Route;
use LaravelSkir\Runtime\Field;
use LaravelSkir\Runtime\MethodDescriptor;
use LaravelSkir\Runtime\Type;
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
        Route::skirRpc('/rpc');

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
