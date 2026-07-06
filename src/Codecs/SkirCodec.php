<?php

declare(strict_types=1);

namespace Skir\Server\Codecs;

use Skir\Runtime\MethodDescriptor;

interface SkirCodec
{
    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed;

    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): mixed;
}
