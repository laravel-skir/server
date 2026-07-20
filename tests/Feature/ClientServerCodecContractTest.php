<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Testing\TestResponse;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Skir\Client\Codecs\SkirClientCodec;
use Skir\Client\Codecs\SkirClientCodecs;
use Skir\Client\Codecs\SkirClientHttpCodec;
use Skir\Runtime\EnumValue;
use Skir\Runtime\Field;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Runtime\Variant;
use Skir\Server\Codecs\SkirCodec;
use Skir\Server\Codecs\SkirCodecs;
use Skir\Server\SkirServer;
use Skir\Server\Tests\TestCase;

final class ClientServerCodecContractTest extends TestCase
{
    #[Test]
    #[DataProvider('codecs')]
    public function actual_client_and_server_codecs_share_the_same_http_contract(string $codecName): void
    {
        $descriptor = $this->profileMethod();
        $clientCodec = $this->clientCodec($codecName);
        $serverCodec = $this->serverCodec($codecName);
        [$clientRequest, $expectedServerRequest] = $this->requestValues($codecName);
        [$serverResponse, $expectedClientResponse] = $this->responseValues($codecName);
        $receivedRequest = null;
        $route = $this->app['router']->skirRpc("/contract/{$codecName}", [], $serverCodec);
        $server = $route->defaults['skirServer'];

        $this->assertInstanceOf(SkirServer::class, $server);

        $server->addMethod(
            $descriptor,
            function (mixed $request) use (&$receivedRequest, $serverResponse): mixed {
                $receivedRequest = $request;

                return $serverResponse;
            },
        );

        $response = $this->postClientRequest(
            "/contract/{$codecName}",
            $descriptor,
            $clientCodec,
            $clientRequest,
        );

        $response->assertOk()->assertHeader('content-type', $this->contentType($clientCodec));
        $this->assertSame(
            $this->canonicalValue($expectedServerRequest),
            $this->canonicalValue($receivedRequest),
        );
        $this->assertSame(
            $this->canonicalValue($expectedClientResponse),
            $this->canonicalValue($clientCodec->decodeResponse($descriptor, $response->getContent())),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function codecs(): iterable
    {
        yield 'dense JSON' => ['dense-json'];
        yield 'standard JSON' => ['standard-json'];
        yield 'base64 dense JSON' => ['base64-dense-json'];
        yield 'CBOR' => ['cbor'];
    }

    private function clientCodec(string $codecName): SkirClientCodec
    {
        return match ($codecName) {
            'dense-json' => SkirClientCodecs::denseJson(),
            'standard-json' => SkirClientCodecs::standardJson(),
            'base64-dense-json' => SkirClientCodecs::base64DenseJson(),
            'cbor' => SkirClientCodecs::cbor(),
        };
    }

    private function serverCodec(string $codecName): SkirCodec
    {
        return match ($codecName) {
            'dense-json' => SkirCodecs::denseJson(),
            'standard-json' => SkirCodecs::standardJson(),
            'base64-dense-json' => SkirCodecs::base64DenseJson(),
            'cbor' => SkirCodecs::cbor(),
        };
    }

    private function profileMethod(): MethodDescriptor
    {
        $statusType = Type::enum([
            Variant::constant('active', 1),
            Variant::wrapper('suspended_reason', 2, Type::string()),
        ]);
        $profileType = Type::struct([
            Field::value('id', 0, Type::int32()),
            Field::value('tags', 1, Type::array(Type::string())),
            Field::value('status', 2, $statusType),
            Field::value('avatar', 3, Type::bytes()),
            Field::value('note', 4, Type::optional(Type::string())),
        ]);

        return new MethodDescriptor('UpdateProfile', 7001, $profileType, $profileType);
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function requestValues(string $codecName): array
    {
        if ($codecName === 'standard-json') {
            $request = [
                'id' => 42,
                'tags' => ['admin', 'beta'],
                'status' => ['suspended_reason' => 'manual review'],
                'avatar' => 'AQID',
                'note' => null,
            ];

            return [$request, $request];
        }

        $request = [
            'id' => 42,
            'tags' => ['admin', 'beta'],
            'status' => EnumValue::wrapper('suspended_reason', 'manual review'),
            'avatar' => "\x01\x02\x03",
            'note' => null,
        ];

        return [$request, $request];
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function responseValues(string $codecName): array
    {
        if ($codecName === 'standard-json') {
            $response = [
                'id' => 42,
                'tags' => ['verified'],
                'status' => 'active',
                'avatar' => 'BAUG',
                'note' => 'updated',
            ];

            return [$response, $response];
        }

        $response = [
            'id' => 42,
            'tags' => ['verified'],
            'status' => EnumValue::constant('active'),
            'avatar' => "\x04\x05\x06",
            'note' => 'updated',
        ];

        return [$response, $response];
    }

    private function postClientRequest(
        string $uri,
        MethodDescriptor $descriptor,
        SkirClientCodec $codec,
        mixed $request,
    ): TestResponse {
        if ($codec instanceof SkirClientHttpCodec) {
            return $this->call(
                'POST',
                $uri,
                server: ['CONTENT_TYPE' => $codec->contentType()],
                content: $codec->encodeRequestBody($descriptor, $request),
            );
        }

        return $this->call(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $this->jsonEncode([
                'method' => $descriptor->name,
                'request' => $codec->encodeRequest($descriptor, $request),
            ]),
        );
    }

    private function contentType(SkirClientCodec $codec): string
    {
        return $codec instanceof SkirClientHttpCodec ? $codec->contentType() : 'application/json';
    }

    private function canonicalValue(mixed $value): mixed
    {
        if ($value instanceof EnumValue) {
            return [
                '__skir_enum' => [
                    'name' => $value->name,
                    'value' => $this->canonicalValue($value->value),
                    'wrapper' => $value->wrapper,
                ],
            ];
        }

        if (! is_array($value)) {
            return $value;
        }

        $canonicalValue = [];

        foreach ($value as $key => $item) {
            $canonicalValue[$key] = $this->canonicalValue($item);
        }

        return $canonicalValue;
    }

    /**
     * @param  array<string, mixed>  $value
     *
     * @throws JsonException
     */
    private function jsonEncode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
