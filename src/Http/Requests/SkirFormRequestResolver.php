<?php

declare(strict_types=1);

namespace Skir\Server\Http\Requests;

use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Redirector;
use Skir\Server\SkirContext;

final readonly class SkirFormRequestResolver
{
    public function __construct(
        private Container $container,
        private Redirector $redirector,
    ) {}

    /**
     * @template TRequest of SkirFormRequest
     *
     * @param  class-string<TRequest>  $requestClass
     * @param  array<string, mixed>  $decodedPayload
     * @return TRequest
     */
    public function resolve(string $requestClass, array $decodedPayload, SkirContext $context): SkirFormRequest
    {
        $resolvedRequest = new $requestClass;

        $requestClass::createFrom($context->request, $resolvedRequest);
        $resolvedRequest->setContainer($this->container);
        $resolvedRequest->setRedirector($this->redirector);
        $resolvedRequest->setUserResolver($context->request->getUserResolver());
        $resolvedRequest->setRouteResolver($context->request->getRouteResolver());
        $resolvedRequest->replace($decodedPayload);
        $resolvedRequest->validateResolved();

        return $resolvedRequest;
    }
}
