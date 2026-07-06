<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

use ReflectionMethod;
use ReflectionNamedType;
use Skir\Server\RequestContext;
use Skir\Server\SkirContext;

final readonly class ControllerProcedureInvoker
{
    /**
     * @param  class-string  $controller
     */
    public function __construct(
        private string $controller,
        private string $method,
    ) {}

    public function __invoke(mixed $request, SkirContext $context): mixed
    {
        $controller = app($this->controller);
        $reflection = new ReflectionMethod($controller, $this->method);
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if ($typeName === SkirContext::class) {
                $arguments[] = $context;

                continue;
            }

            if ($typeName === RequestContext::class) {
                $arguments[] = $context;

                continue;
            }

            $arguments[] = $this->hydrateRequest($typeName, $request);
        }

        return $this->normalizeResponse($reflection->invokeArgs($controller, $arguments));
    }

    private function hydrateRequest(?string $typeName, mixed $request): mixed
    {
        if ($typeName === null) {
            return $request;
        }

        if (! class_exists($typeName)) {
            return $request;
        }

        if (method_exists($typeName, 'makeFromSkirPayload')) {
            return $typeName::makeFromSkirPayload($request);
        }

        if (method_exists($typeName, 'fromArray')) {
            return $typeName::fromArray($request);
        }

        return $request;
    }

    private function normalizeResponse(mixed $response): mixed
    {
        if (! is_object($response)) {
            return $response;
        }

        if (method_exists($response, 'toSkirArray')) {
            return $response->toSkirArray();
        }

        if (method_exists($response, 'toArray')) {
            return $response->toArray();
        }

        return $response;
    }
}
