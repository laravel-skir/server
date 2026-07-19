<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\Cbor;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Codecs\Base64DenseJsonCodec;
use Skir\Server\Codecs\CborCodec;
use Skir\Server\Codecs\DenseJsonCodec;
use Skir\Server\Codecs\SkirCodecs;
use Skir\Server\Codecs\StandardJsonCodec;
use Skir\Server\ProcedureProvider;
use Skir\Server\SkirServer;
use Skir\Server\Tests\TestCase;

final class SkirCodecFailureTest extends TestCase
{
    #[Test]
    #[DataProvider('invalidBase64Requests')]
    public function it_rejects_invalid_base64_dense_json_requests(mixed $request): void
    {
        Route::skirRpc('/base64-validation', [
            new CodecSquareProvider,
        ], SkirCodecs::base64DenseJson());

        $this
            ->postJson('/base64-validation', [
                'method' => 'CountItems',
                'request' => $request,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'skir_invalid_request');
    }

    #[Test]
    public function it_rejects_a_missing_base64_request_value(): void
    {
        Route::skirRpc('/base64-missing', [
            new CodecSquareProvider,
        ], SkirCodecs::base64DenseJson());

        $this
            ->postJson('/base64-missing', [
                'method' => 'CountItems',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'skir_invalid_request');
    }

    #[Test]
    public function it_rejects_malformed_cbor(): void
    {
        Route::skirRpc('/cbor-malformed', [
            new CodecSquareProvider,
        ], SkirCodecs::cbor());

        $this
            ->call(
                'POST',
                '/cbor-malformed',
                server: ['CONTENT_TYPE' => 'application/cbor'],
                content: "\xff",
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'skir_invalid_request');
    }

    #[Test]
    public function it_rejects_non_map_cbor_envelopes(): void
    {
        Route::skirRpc('/cbor-map', [
            new CodecSquareProvider,
        ], SkirCodecs::cbor());

        $this
            ->call(
                'POST',
                '/cbor-map',
                server: ['CONTENT_TYPE' => 'application/cbor'],
                content: Cbor::encode(5),
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'skir_invalid_request');
    }

    #[Test]
    public function it_rejects_cbor_envelopes_without_a_method(): void
    {
        Route::skirRpc('/cbor-method', [
            new CodecSquareProvider,
        ], SkirCodecs::cbor());

        $this
            ->call(
                'POST',
                '/cbor-method',
                server: ['CONTENT_TYPE' => 'application/cbor'],
                content: Cbor::encode(['request' => 5.0]),
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'skir_invalid_request');
    }

    #[Test]
    public function it_returns_the_package_error_for_unknown_cbor_methods(): void
    {
        Route::skirRpc('/cbor-unknown', [
            new CodecSquareProvider,
        ], SkirCodecs::cbor());

        $this
            ->call(
                'POST',
                '/cbor-unknown',
                server: ['CONTENT_TYPE' => 'application/cbor'],
                content: Cbor::encode([
                    'method' => 'Missing',
                    'request' => 5.0,
                ]),
            )
            ->assertNotFound()
            ->assertJsonPath('error.code', 'skir_method_not_found');
    }

    #[Test]
    public function it_rejects_cbor_values_that_do_not_match_the_request_type(): void
    {
        Route::skirRpc('/cbor-type', [
            new CodecSquareProvider,
        ], SkirCodecs::cbor());

        $this
            ->call(
                'POST',
                '/cbor-type',
                server: ['CONTENT_TYPE' => 'application/cbor'],
                content: Cbor::encode([
                    'method' => 'CountItems',
                    'request' => 'five',
                ]),
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'skir_invalid_request');
    }

    #[Test]
    public function codec_factories_return_the_expected_codec_types(): void
    {
        $this->assertInstanceOf(DenseJsonCodec::class, SkirCodecs::denseJson());
        $this->assertInstanceOf(StandardJsonCodec::class, SkirCodecs::standardJson());
        $this->assertInstanceOf(Base64DenseJsonCodec::class, SkirCodecs::base64DenseJson());
        $this->assertInstanceOf(CborCodec::class, SkirCodecs::cbor());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidBase64Requests(): array
    {
        return [
            'non-string' => [5],
            'invalid base64' => ['***'],
            'invalid decoded JSON' => [base64_encode('{')],
            'invalid dense JSON type' => [base64_encode('"five"')],
        ];
    }
}

final class CodecSquareProvider implements ProcedureProvider
{
    public function register(SkirServer $server): void
    {
        $server->addMethod(
            new MethodDescriptor('CountItems', 1001, Type::array(Type::int32()), Type::int32()),
            fn (array $items): int => count($items),
        );
    }
}
