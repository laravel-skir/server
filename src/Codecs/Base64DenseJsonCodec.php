<?php

declare(strict_types=1);

namespace Skir\Server\Codecs;

use JsonException;
use Skir\Runtime\DenseJson;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Runtime\MethodDescriptor;

final class Base64DenseJsonCodec implements SkirCodec
{
    public function decodeRequest(MethodDescriptor $descriptor, mixed $request): mixed
    {
        if (! is_string($request)) {
            throw SkirRuntimeException::invalidDenseJson('Base64 dense JSON requests must be strings.');
        }

        $json = base64_decode($request, true);

        if ($json === false) {
            throw SkirRuntimeException::invalidDenseJson('Base64 dense JSON request is not valid base64.');
        }

        try {
            $denseRequest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw SkirRuntimeException::invalidDenseJson($exception->getMessage(), $exception);
        }

        return DenseJson::decode($descriptor->requestType, $denseRequest);
    }

    public function encodeResponse(MethodDescriptor $descriptor, mixed $response): mixed
    {
        return base64_encode(DenseJson::toJson($descriptor->responseType, $response));
    }
}
