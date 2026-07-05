<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Codecs;

use LaravelSkir\Runtime\MethodDescriptor;

interface SkirCodec
{
    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed;

    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): mixed;
}
