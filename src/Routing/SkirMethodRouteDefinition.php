<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\SkirServer;

final readonly class SkirMethodRouteDefinition implements SkirRouteDefinition
{
    /**
     * @param  class-string  $controller
     */
    public function __construct(
        private SkirMethodReference $method,
        private string $controller,
    ) {}

    public function register(SkirServer $server): void
    {
        $descriptor = $this->method->descriptor();

        $server->addMethod(
            $descriptor,
            new ControllerProcedureInvoker($this->controller, '__invoke'),
        );
    }
}
