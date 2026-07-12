<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionNamedType;
use Skir\Server\Http\Requests\SkirFormRequest;
use Skir\Server\Http\Requests\SkirFormRequestResolver;
use Skir\Server\Hydration\SkirPayloadHydrator;
use Skir\Server\RequestContext;
use Skir\Server\SkirContext;

/** @internal */
final readonly class ControllerProcedureInvoker
{
    /**
     * @param  class-string  $controller
     */
    public function __construct(
        private string $controller,
        private string $method,
        private Container $container,
        private SkirPayloadHydrator $payloadHydrator,
        private SkirFormRequestResolver $formRequestResolver,
    ) {}

    public function __invoke(mixed $request, SkirContext $context): mixed
    {
        $controller = $this->container->make($this->controller);
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

            if ($this->isFormRequest($typeName)) {
                if (! is_array($request)) {
                    throw new InvalidArgumentException('Skir Form Requests require a decoded array/object payload.');
                }

                /** @var class-string<SkirFormRequest> $typeName */
                $arguments[] = $this->formRequestResolver->resolve($typeName, $request, $context);

                continue;
            }

            if ($typeName !== null) {
                if ($this->payloadHydrator->supports($typeName)) {
                    /** @var class-string $typeName */
                    $arguments[] = $this->payloadHydrator->hydrate($typeName, $request);

                    continue;
                }
            }

            $arguments[] = $request;
        }

        return $this->normalizeResponse($reflection->invokeArgs($controller, $arguments));
    }

    private function isFormRequest(?string $typeName): bool
    {
        if ($typeName === null) {
            return false;
        }

        if (! class_exists($typeName)) {
            return false;
        }

        return is_a($typeName, SkirFormRequest::class, true);
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
