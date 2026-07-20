<?php

declare(strict_types=1);

namespace Skir\Server\Http\Requests;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class SkirMethodRequestScope
{
    public function __construct(private Container $container) {}

    /** @param Closure(Request, mixed): mixed $callback */
    public function run(Request $transportRequest, mixed $payload, Closure $callback): mixed
    {
        $originalRequest = $this->container->make('request');
        $originalUserResolver = $originalRequest->getUserResolver();
        $methodRequest = Request::createFrom($transportRequest);
        $methodUserResolver = $methodRequest->getUserResolver();

        $methodRequest->query->replace([]);
        $methodRequest->request->replace([]);
        $methodRequest->files->replace([]);
        $methodRequest->setJson(new InputBag);

        $this->bindDecodedInput(
            $methodRequest,
            new InputBag(is_array($payload) ? $payload : []),
        );

        $this->bindRequest($methodRequest, $methodUserResolver);

        try {
            return $callback($methodRequest, $payload);
        } finally {
            $this->bindRequest($originalRequest, $originalUserResolver);
        }
    }

    private function bindDecodedInput(Request $request, InputBag $decodedInput): void
    {
        if ($request->isJson()) {
            $request->setJson($decodedInput);

            return;
        }

        if ($request->isMethod('GET')) {
            $request->query = $decodedInput;

            return;
        }

        if ($request->isMethod('HEAD')) {
            $request->query = $decodedInput;

            return;
        }

        $request->request = $decodedInput;
    }

    private function bindRequest(Request $request, Closure $userResolver): void
    {
        $this->container->instance('request', $request);
        $request->setUserResolver($userResolver);
        RequestFacade::clearResolvedInstance('request');
    }
}
