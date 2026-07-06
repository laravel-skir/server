<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

use ReflectionClass;
use ReflectionMethod;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\SkirServer;

final readonly class SkirControllerRouteDefinition implements SkirRouteDefinition
{
    /**
     * @param  class-string  $controller
     */
    public function __construct(
        private string $controller,
    ) {}

    public function register(SkirServer $server): void
    {
        $reflection = new ReflectionClass($this->controller);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }

            if ($method->isStatic()) {
                continue;
            }

            $attributes = $method->getAttributes(SkirMethod::class);

            if ($attributes === []) {
                continue;
            }

            /** @var SkirMethod $attribute */
            $attribute = $attributes[0]->newInstance();
            $descriptor = $attribute->method->descriptor();

            $server->addMethod(
                $descriptor,
                new ControllerProcedureInvoker($this->controller, $method->getName()),
            );
        }
    }
}
