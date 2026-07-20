<?php

declare(strict_types=1);

namespace Skir\Server;

use Closure;
use Skir\Runtime\MethodDescriptor;
use Skir\Server\Contracts\PreparesProcedure;

final readonly class RegisteredProcedure
{
    private Closure $handler;

    private ?PreparesProcedure $preparer;

    public function __construct(
        public MethodDescriptor $descriptor,
        callable $handler,
    ) {
        $this->preparer = $handler instanceof PreparesProcedure ? $handler : null;
        $this->handler = Closure::fromCallable($handler);
    }

    public function prepare(mixed $request, SkirContext $context): PreparedProcedure
    {
        if ($this->preparer instanceof PreparesProcedure) {
            return $this->preparer->prepare($request, $context);
        }

        return new PreparedProcedure(
            [],
            fn (): mixed => ($this->handler)($request, $context),
        );
    }

    public function invoke(mixed $request, SkirContext $context): mixed
    {
        return ($this->handler)($request, $context);
    }
}
