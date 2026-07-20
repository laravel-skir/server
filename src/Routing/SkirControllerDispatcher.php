<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Skir\Server\Exceptions\SkirServerException;

final class SkirControllerDispatcher extends ControllerDispatcher
{
    public function dispatch(Route $route, $controller, $method): mixed
    {
        try {
            $parameters = $this->resolveParameters($route, $controller, $method);
        } catch (ValidationException $exception) {
            throw SkirServerException::validationFailed($exception->errors());
        } catch (AuthorizationException) {
            throw SkirServerException::authorizationFailed();
        }

        $preparedParameters = $route->getAction('skirParameters');

        if ($preparedParameters instanceof PreparedControllerParameters) {
            $parameters = $preparedParameters->restoreNullParameters($parameters);
        }

        if (method_exists($controller, 'callAction')) {
            return $controller->callAction($method, $parameters);
        }

        return $controller->{$method}(...array_values($parameters));
    }

    /** @return list<mixed> */
    protected function resolveParameters(Route $route, $controller, $method): array
    {
        $preparedParameters = $route->getAction('skirParameters');

        if (! $preparedParameters instanceof PreparedControllerParameters) {
            return parent::resolveParameters($route, $controller, $method);
        }

        return $this->resolveClassMethodDependencies(
            $preparedParameters->values,
            $controller,
            $method,
        );
    }
}
