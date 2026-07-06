<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Codecs;

use CBOR\Encoder;
use LaravelSkir\Server\Exceptions\SkirServerException;

final class SkirCodecs
{
    public static function cbor(): SkirCodec
    {
        if (! class_exists(Encoder::class)) {
            throw SkirServerException::missingCborDependency();
        }

        return new CborCodec;
    }

    public static function denseJson(): SkirCodec
    {
        return new DenseJsonCodec;
    }

    public static function standardJson(): SkirCodec
    {
        return new StandardJsonCodec;
    }

    public static function base64DenseJson(): SkirCodec
    {
        return new Base64DenseJsonCodec;
    }
}
