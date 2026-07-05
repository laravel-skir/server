<?php

declare(strict_types=1);

namespace LaravelSkir\Server;

use Illuminate\Http\Request;
use LaravelSkir\Runtime\MethodDescriptor;

final readonly class RequestContext
{
    public function __construct(
        public Request $request,
        public MethodDescriptor $method,
    ) {}
}
