<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

use Illuminate\Contracts\Container\Container;
use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\Http\Requests\SkirFormRequestResolver;
use Skir\Server\Hydration\SkirPayloadHydrator;
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
            new ControllerProcedureInvoker(
                $this->controller,
                '__invoke',
                app(Container::class),
                app(SkirPayloadHydrator::class),
                app(SkirFormRequestResolver::class),
            ),
        );
    }
}
