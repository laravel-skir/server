<?php

declare(strict_types=1);

namespace Skir\Server;

use Closure;

final readonly class PreparedProcedure
{
    private Closure $invocation;

    /**
     * @param  list<Closure|string>  $middleware
     */
    public function __construct(
        public array $middleware,
        callable $invocation,
    ) {
        $this->invocation = Closure::fromCallable($invocation);
    }

    public function invoke(): mixed
    {
        return ($this->invocation)();
    }
}
