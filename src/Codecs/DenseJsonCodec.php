<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Codecs;

use LaravelSkir\Runtime\DenseJson;
use LaravelSkir\Runtime\MethodDescriptor;

final class DenseJsonCodec implements SkirCodec
{
    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed
    {
        return DenseJson::decode($descriptor->requestType, $request);
    }

    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): mixed
    {
        return DenseJson::encode($descriptor->responseType, $response);
    }
}
