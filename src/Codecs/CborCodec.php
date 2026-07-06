<?php

declare(strict_types=1);

namespace Skir\Server\Codecs;

use Skir\Runtime\Cbor;
use Skir\Runtime\MethodDescriptor;

final class CborCodec implements SkirHttpCodec
{
    public function decodePayload(string $content): array
    {
        return Cbor::decodeEnvelope($content);
    }

    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed
    {
        return Cbor::decodeValuePayload($descriptor->requestType, $request);
    }

    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): string
    {
        return Cbor::encodeValue($descriptor->responseType, $response);
    }

    public function contentType(): string
    {
        return 'application/cbor';
    }
}
