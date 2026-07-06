<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Codecs;

use CBOR\Decoder;
use CBOR\Encoder;
use CBOR\StringStream;
use LaravelSkir\Runtime\DenseJson;
use LaravelSkir\Runtime\Exceptions\SkirRuntimeException;
use LaravelSkir\Runtime\MethodDescriptor;
use LaravelSkir\Server\Exceptions\SkirServerException;
use Throwable;

final class CborCodec implements SkirHttpCodec
{
    public function decodePayload(string $content): array
    {
        try {
            $payload = Decoder::create()
                ->decode(StringStream::create($content))
                ->normalize();
        } catch (Throwable $exception) {
            throw SkirServerException::invalidRequest("Invalid CBOR request: {$exception->getMessage()}");
        }

        if (! is_array($payload)) {
            throw SkirServerException::invalidRequest('Skir CBOR requests must be maps.');
        }

        return $payload;
    }

    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed
    {
        return DenseJson::decode($descriptor->requestType, $request);
    }

    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): string
    {
        try {
            return (new Encoder)->encode(DenseJson::encode($descriptor->responseType, $response));
        } catch (Throwable $exception) {
            throw SkirRuntimeException::invalidValue("Skir CBOR response could not be encoded: {$exception->getMessage()}");
        }
    }

    public function contentType(): string
    {
        return 'application/cbor';
    }
}
