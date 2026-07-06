<?php

declare(strict_types=1);

namespace Skir\Server;

use Closure;
use Skir\Runtime\MethodDescriptor;

final readonly class RegisteredProcedure
{
    private Closure $handler;

    public function __construct(
        public MethodDescriptor $descriptor,
        callable $handler,
    ) {
        $this->handler = Closure::fromCallable($handler);
    }

    public function invoke(mixed $request, SkirContext $context): mixed
    {
        return ($this->handler)($request, $context);
    }
}
