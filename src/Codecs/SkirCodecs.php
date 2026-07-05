<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Codecs;

final class SkirCodecs
{
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
