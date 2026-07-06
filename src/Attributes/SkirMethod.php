<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Attributes;

use Attribute;
use LaravelSkir\Server\Contracts\SkirMethodReference;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class SkirMethod
{
    public function __construct(
        public SkirMethodReference $method,
    ) {}
}
