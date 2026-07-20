<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

use Illuminate\Container\Util;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Skir\Server\Contracts\PreparesProcedure;
use Skir\Server\Http\Requests\SkirFormRequest;
use Skir\Server\Http\Requests\SkirFormRequestResolver;
use Skir\Server\Hydration\SkirPayloadHydrator;
use Skir\Server\PreparedProcedure;
use Skir\Server\RequestContext;
use Skir\Server\SkirContext;

/** @internal */
final readonly class ControllerProcedureInvoker implements PreparesProcedure
{
    /**
     * @param  class-string  $controller
     */
    public function __construct(
        private string $controller,
        private string $method,
        private SkirPayloadHydrator $payloadHydrator,
        private SkirFormRequestResolver $formRequestResolver,
        private Router $router,
        private SkirControllerDispatcher $dispatcher,
    ) {}

    public function __invoke(mixed $request, SkirContext $context): mixed
    {
        return $this->prepare($request, $context)->invoke();
    }

    public function prepare(mixed $request, SkirContext $context): PreparedProcedure
    {
        $route = $this->controllerRoute($context);
        $controller = $route->getController();

        $middleware = $this->router->resolveMiddleware(
            $route->controllerMiddleware(),
            $route->excludedControllerMiddleware(),
        );

        return new PreparedProcedure(
            $middleware,
            fn (): mixed => $this->invokePrepared($route, $controller, $request, $context),
        );
    }

    private function invokePrepared(
        Route $route,
        object $controller,
        mixed $request,
        SkirContext $context,
    ): mixed {
        $preparedParameters = $this->prepareParameters($controller, $request, $context);
        $action = $route->getAction();
        $action['skirParameters'] = $preparedParameters;
        $route->setAction($action);

        return $this->normalizeResponse(
            $this->dispatcher->dispatch($route, $controller, $this->method),
        );
    }

    private function controllerRoute(SkirContext $context): Route
    {
        $outerRoute = $context->request->route();

        if (! $outerRoute instanceof Route) {
            throw new InvalidArgumentException('Skir controller procedures require a bound Laravel route.');
        }

        $route = clone $outerRoute;
        $action = "{$this->controller}@{$this->method}";
        $route->setAction([
            'uses' => $action,
            'controller' => $action,
        ]);
        $route->flushController();

        return $route;
    }

    private function prepareParameters(
        object $controller,
        mixed $request,
        SkirContext $context,
    ): PreparedControllerParameters {
        $reflection = new ReflectionMethod($controller, $this->method);
        $values = [];
        $nullParameterIndexes = [];
        $outerRoute = $context->request->route();

        foreach ($reflection->getParameters() as $parameterIndex => $parameter) {
            if (Util::getContextualAttributeFromDependency($parameter) !== null) {
                continue;
            }

            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $isBuiltin = $type instanceof ReflectionNamedType && $type->isBuiltin();

            if ($typeName === SkirContext::class || $typeName === RequestContext::class) {
                $values[] = $context;

                continue;
            }

            if ($outerRoute instanceof Route && $outerRoute->hasParameter($parameter->getName())) {
                $values[] = $outerRoute->parameter($parameter->getName());

                continue;
            }

            if ($this->isFormRequest($typeName)) {
                if ($request === null && $type?->allowsNull()) {
                    /** @var class-string<SkirFormRequest> $typeName */
                    $marker = $this->formRequestResolver->authorizeNull($typeName, $context);
                    $values[] = $marker;
                    $nullParameterIndexes[] = $parameterIndex;

                    continue;
                }

                if (! is_array($request)) {
                    throw new InvalidArgumentException('Skir Form Requests require a decoded array/object payload.');
                }

                /** @var class-string<SkirFormRequest> $typeName */
                $values[] = $this->formRequestResolver->resolve($typeName, $request, $context);

                continue;
            }

            if ($this->isLaravelFormRequest($typeName)) {
                throw new InvalidArgumentException(
                    'Skir controller Form Requests must extend ['.SkirFormRequest::class.'].',
                );
            }

            if ($request === null && $type?->allowsNull()) {
                $marker = $this->nullablePayloadMarker($typeName);

                if ($marker !== null) {
                    $values[] = $marker;
                    $nullParameterIndexes[] = $parameterIndex;

                    continue;
                }

                if ($typeName === null || $isBuiltin) {
                    $values[] = null;
                }

                continue;
            }

            if ($typeName !== null && $this->payloadHydrator->supports($typeName)) {
                /** @var class-string $typeName */
                $values[] = $this->payloadHydrator->hydrate($typeName, $request);

                continue;
            }

            if ($typeName === null || $isBuiltin) {
                $values[] = $request;
            }
        }

        return new PreparedControllerParameters($values, $nullParameterIndexes);
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

    private function isLaravelFormRequest(?string $typeName): bool
    {
        if ($typeName === null) {
            return false;
        }

        if (! class_exists($typeName)) {
            return false;
        }

        return is_a($typeName, FormRequest::class, true);
    }

    private function nullablePayloadMarker(?string $typeName): ?object
    {
        if ($typeName === null) {
            return null;
        }

        if (! class_exists($typeName)) {
            return null;
        }

        if (! $this->payloadHydrator->supports($typeName)) {
            return null;
        }

        $reflection = new ReflectionClass($typeName);

        if ($reflection->isEnum()) {
            /** @var list<object> $cases */
            $cases = $typeName::cases();

            return $cases[0] ?? null;
        }

        return $reflection->newInstanceWithoutConstructor();
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
