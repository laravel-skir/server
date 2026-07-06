<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Codecs;

interface SkirHttpCodec extends SkirCodec
{
    /**
     * @return array{
     *     method?: mixed,
     *     request?: mixed
     * }
     */
    public function decodePayload(string $content): array;

    public function contentType(): string;
}
