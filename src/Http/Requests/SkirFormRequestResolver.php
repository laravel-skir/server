<?php

declare(strict_types=1);

namespace Skir\Server\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\SkirContext;
use Symfony\Component\HttpFoundation\InputBag;

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
        $resolvedRequest = $this->makeRequest($requestClass, $decodedPayload, $context);

        try {
            $resolvedRequest->validateResolved();
        } catch (ValidationException $exception) {
            throw SkirServerException::validationFailed($exception->errors());
        } catch (AuthorizationException) {
            throw SkirServerException::authorizationFailed();
        }

        return $resolvedRequest;
    }

    /** @param class-string<SkirFormRequest> $requestClass */
    public function authorizeNull(string $requestClass, SkirContext $context): void
    {
        $resolvedRequest = $this->makeRequest($requestClass, [], $context);

        try {
            $resolvedRequest->authorizeResolved();
        } catch (AuthorizationException) {
            throw SkirServerException::authorizationFailed();
        }
    }

    /**
     * @template TRequest of SkirFormRequest
     *
     * @param  class-string<TRequest>  $requestClass
     * @param  array<string, mixed>  $decodedPayload
     * @return TRequest
     */
    private function makeRequest(string $requestClass, array $decodedPayload, SkirContext $context): SkirFormRequest
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
        $resolvedRequest->replace($decodedPayload);

        return $resolvedRequest;
    }
}
