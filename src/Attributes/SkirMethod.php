<?php

declare(strict_types=1);

namespace Skir\Server\Attributes;

use Attribute;
use Skir\Server\Contracts\SkirMethodReference;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class SkirMethod
{
    public function __construct(
        public SkirMethodReference $method,
    ) {}
}
