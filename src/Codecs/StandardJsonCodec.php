<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Codecs;

use LaravelSkir\Runtime\MethodDescriptor;

final class StandardJsonCodec implements SkirCodec
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
