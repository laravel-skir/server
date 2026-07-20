<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\ProcedureProvider;
use Skir\Server\SkirServer;
use Skir\Server\Tests\TestCase;

final class SkirRpcValidationTest extends TestCase
{
    #[Test]
    #[DataProvider('nonStringMethods')]
    public function it_rejects_non_string_method_values(mixed $method): void
    {
        $this
            ->postJson('/skir', [
                'method' => $method,
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

    #[Test]
    public function it_rejects_malformed_json_envelopes(): void
    {
        $this
            ->call(
                'POST',
                '/skir',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: '{',
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'skir_invalid_request');
    }

    #[Test]
    #[DataProvider('nonObjectJsonEnvelopes')]
    public function it_rejects_non_object_json_envelopes(string $content): void
    {
        $this
            ->call(
                'POST',
                '/skir',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: $content,
            )
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_invalid_request',
                    'message' => 'Skir requests must be JSON objects.',
                ],
            ]);
    }

    #[Test]
    public function it_rejects_malformed_get_request_json(): void
    {
        $this->registerSquare();

        $this
            ->getJson('/skir?method=Square&request=%7B')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'skir_invalid_request');
    }

    #[Test]
    public function it_rejects_dense_json_that_does_not_match_the_request_type(): void
    {
        app(SkirServer::class)->addMethod(
            new MethodDescriptor('CountItems', 3002, Type::array(Type::int32()), Type::int32()),
            fn (array $items): int => count($items),
        );

        $this
            ->postJson('/skir', [
                'method' => 'CountItems',
                'request' => 'five',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'skir_invalid_request');
    }

    #[Test]
    public function it_preserves_an_explicit_null_request(): void
    {
        app(SkirServer::class)->addMethod(
            new MethodDescriptor('DescribeNull', 3001, Type::optional(Type::string()), Type::string()),
            fn (mixed $value): string => $value === null ? 'null' : 'not-null',
        );

        $this
            ->postJson('/skir', [
                'method' => 'DescribeNull',
                'request' => null,
            ])
            ->assertOk()
            ->assertContent('"null"');
    }

    #[Test]
    public function it_ignores_additional_envelope_fields(): void
    {
        $this->registerSquare();

        $this
            ->postJson('/skir', [
                'method' => 'Square',
                'request' => 5.0,
                'trace_id' => 'trace-123',
            ])
            ->assertOk()
            ->assertContent('25');
    }

    #[Test]
    public function post_requests_with_a_studio_query_still_dispatch_rpc(): void
    {
        Route::skirRpc('/studio-post', [
            new ValidationSquareProvider,
        ])->studio();

        $this
            ->postJson('/studio-post?studio', [
                'method' => 'Square',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertContent('25');
    }

    #[Test]
    public function it_rejects_unsupported_http_methods(): void
    {
        $this
            ->putJson('/skir', [
                'method' => 'Square',
                'request' => 5.0,
            ])
            ->assertMethodNotAllowed();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonStringMethods(): array
    {
        return [
            'null' => [null],
            'integer' => [1001],
            'array' => [['Square']],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonObjectJsonEnvelopes(): array
    {
        return [
            'null' => ['null'],
            'number' => ['5'],
            'string' => ['"value"'],
            'list' => ['[]'],
        ];
    }

    private function registerSquare(): void
    {
        app(SkirServer::class)->addMethod(
            new MethodDescriptor('Square', 1001, Type::float32(), Type::float32()),
            fn (float $value): float => $value * $value,
        );
    }
}

final class ValidationSquareProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('Square', 1001, Type::float32(), Type::float32()),
            fn (float $value): float => $value * $value,
        );
    }
}
