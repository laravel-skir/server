<?php

declare(strict_types=1);

use Skir\Server\Codecs\DenseJsonCodec;

return [
    'studio_enabled' => env('SKIR_SERVER_STUDIO_ENABLED', false),
    'studio_query_key' => env('SKIR_SERVER_STUDIO_QUERY_KEY', 'studio'),
    'codec' => DenseJsonCodec::class,
];
