<?php

declare(strict_types=1);

namespace Skir\Server\Http\Requests;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class SkirMethodRequestScope
{
    public function __construct(private Container $container) {}

    /** @param Closure(Request, mixed): mixed $callback */
    public function run(Request $transportRequest, mixed $payload, Closure $callback): mixed
    {
        $originalRequest = $this->container->make('request');
        $methodRequest = Request::createFrom($transportRequest);

        $methodRequest->query->replace([]);
        $methodRequest->request->replace([]);
        $methodRequest->files->replace([]);
        $methodRequest->setJson(new InputBag);

        if (is_array($payload)) {
            $methodRequest->replace($payload);
        }

        $this->container->instance('request', $methodRequest);

        try {
            return $callback($methodRequest, $payload);
        } finally {
            $this->container->instance('request', $originalRequest);
        }
    }
}
