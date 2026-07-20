<?php

declare(strict_types=1);

namespace Skir\Server\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Redirector;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\SkirContext;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class SkirFormRequestResolver
{
    public function __construct(
        private Container $container,
        private Redirector $redirector,
    ) {}

    /** @param class-string<SkirFormRequest> $requestClass */
    public function authorizeNull(string $requestClass, SkirContext $context): SkirFormRequest
    {
        $resolvedRequest = $this->makeRequest($requestClass, $context);

        try {
            $resolvedRequest->authorizeResolved();
        } catch (AuthorizationException) {
            throw SkirServerException::authorizationFailed();
        }

        return $resolvedRequest;
    }

    /**
     * @template TRequest of SkirFormRequest
     *
     * @param  class-string<TRequest>  $requestClass
     * @return TRequest
     */
    private function makeRequest(string $requestClass, SkirContext $context): SkirFormRequest
    {
        $resolvedRequest = new $requestClass;

        $requestClass::createFrom($context->request, $resolvedRequest);
        $resolvedRequest->setContainer($this->container);
        $resolvedRequest->setRedirector($this->redirector);
        $resolvedRequest->setUserResolver($context->request->getUserResolver());
        $resolvedRequest->setRouteResolver($context->request->getRouteResolver());
        $resolvedRequest->query->replace([]);
        $resolvedRequest->request->replace([]);
        $resolvedRequest->files->replace([]);
        $resolvedRequest->setJson(new InputBag);

        return $resolvedRequest;
    }
}
