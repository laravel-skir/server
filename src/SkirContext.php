<?php

declare(strict_types=1);

namespace Skir\Server;

use Illuminate\Http\Request;
use Skir\Runtime\MethodDescriptor;

readonly class SkirContext
{
    public function __construct(
        public Request $request,
        public MethodDescriptor $method,
    ) {}
}
