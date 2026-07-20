<?php

declare(strict_types=1);

namespace Skir\Server\Http\Controllers;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Router;
use JsonException;
use Skir\Runtime\Exceptions\SkirRuntimeException;
use Skir\Server\Codecs\SkirCodec;
use Skir\Server\Codecs\SkirHttpCodec;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\Http\Requests\SkirMethodRequestScope;
use Skir\Server\PreparedProcedure;
use Skir\Server\RegisteredProcedure;
use Skir\Server\RequestContext;
use Skir\Server\SkirServer;
use Skir\Server\Studio\StudioRenderer;
use Symfony\Component\HttpFoundation\Response;

final readonly class SkirRpcController
{
    public function __construct(
        private SkirServer $server,
        private StudioRenderer $studioRenderer,
        private Container $container,
        private SkirMethodRequestScope $requestScope,
        private Router $router,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $server = $this->serverFromRequest($request);

            if ($this->isStudioRequest($request)) {
                if (! $this->studioEnabled($request)) {
                    abort(Response::HTTP_NOT_FOUND);
                }

                return response(
                    $this->studioRenderer->render($server, '/'.$request->path()),
                    Response::HTTP_OK,
                )->header('content-type', 'text/html; charset=UTF-8');
            }

            $codec = $server->codec();
            $payload = $this->payloadFromRequest($request, $codec);
            $method = $this->methodFromPayload($payload);
            $procedure = $server->procedure($method);
            $requestPayload = array_key_exists('request', $payload) ? $payload['request'] : 0;
            $decodedRequest = $codec->decodeRequest($procedure->descriptor, $requestPayload);

            return $this->requestScope->run(
                $request,
                $decodedRequest,
                function (Request $methodRequest, mixed $decodedPayload) use ($codec, $procedure): Response {
                    $context = new RequestContext($methodRequest, $procedure->descriptor);
                    $prepared = $procedure->prepare($decodedPayload, $context);

                    $response = (new Pipeline($this->container))
                        ->send($methodRequest)
                        ->through($this->procedureMiddleware($prepared))
                        ->then(fn (): Response => $this->invokeAndEncodeResponse(
                            $codec,
                            $procedure,
                            $prepared,
                        ));

                    return $this->router->prepareResponse($methodRequest, $response);
                },
            );
        } catch (SkirServerException $exception) {
            return $exception->toResponse();
        } catch (SkirRuntimeException $exception) {
            return SkirServerException::invalidRequest($exception->getMessage())->toResponse();
        }
    }

    private function invokeAndEncodeResponse(
        SkirCodec $codec,
        RegisteredProcedure $procedure,
        PreparedProcedure $prepared,
    ): Response {
        try {
            return $this->encodeResponse($codec, $procedure, $prepared->invoke());
        } catch (SkirRuntimeException $exception) {
            throw SkirServerException::invalidRequest($exception->getMessage());
        }
    }

    private function encodeResponse(
        SkirCodec $codec,
        RegisteredProcedure $procedure,
        mixed $result,
    ): Response {
        $encodedResponse = $codec->encodeResponse($procedure->descriptor, $result);

        if ($codec instanceof SkirHttpCodec) {
            return response($encodedResponse, Response::HTTP_OK)
                ->header('content-type', $codec->contentType());
        }

        return response()->json($encodedResponse, Response::HTTP_OK);
    }

    /** @return list<Closure|string> */
    private function procedureMiddleware(PreparedProcedure $prepared): array
    {
        if ($this->container->bound('middleware.disable')) {
            if ($this->container->make('middleware.disable') === true) {
                return [];
            }
        }

        return $prepared->middleware;
    }

    private function serverFromRequest(Request $request): SkirServer
    {
        $server = $request->route()?->defaults['skirServer'] ?? null;

        if ($server instanceof SkirServer) {
            return $server;
        }

        return $this->server;
    }

    private function isStudioRequest(Request $request): bool
    {
        if (! $this->usesQueryString($request)) {
            return false;
        }

        $queryKey = (string) ($request->route()?->defaults['skirStudioQueryKey'] ?? 'studio');

        return $request->query->has($queryKey);
    }

    private function studioEnabled(Request $request): bool
    {
        return ($request->route()?->defaults['skirStudioEnabled'] ?? false) === true;
    }

    /**
     * @return array{
     *     method?: mixed,
     *     request?: mixed
     * }
     */
    private function payloadFromRequest(Request $request, SkirCodec $codec): array
    {
        if ($this->usesQueryString($request)) {
            return $this->payloadFromQueryString($request);
        }

        if ($codec instanceof SkirHttpCodec) {
            return $codec->decodePayload($request->getContent());
        }

        try {
            $decodedPayload = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw SkirServerException::invalidRequest("Invalid JSON: {$exception->getMessage()}");
        }

        if (! is_object($decodedPayload)) {
            throw SkirServerException::invalidRequest('Skir requests must be JSON objects.');
        }

        return $request->json()->all();
    }

    /**
     * @return array{
     *     method?: mixed,
     *     request?: mixed
     * }
     */
    private function payloadFromQueryString(Request $request): array
    {
        $payload = [
            'method' => $request->query('method'),
        ];

        if (! $request->query->has('request')) {
            return $payload;
        }

        try {
            $payload['request'] = json_decode((string) $request->query('request'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw SkirServerException::invalidRequest("Invalid dense JSON: {$exception->getMessage()}");
        }

        return $payload;
    }

    private function usesQueryString(Request $request): bool
    {
        if ($request->isMethod('GET')) {
            return true;
        }

        return $request->isMethod('HEAD');
    }

    /**
     * @param  array{
     *     method?: mixed,
     *     request?: mixed
     * }  $payload
     */
    private function methodFromPayload(array $payload): string
    {
        if (! array_key_exists('method', $payload)) {
            throw SkirServerException::invalidRequest('Skir requests must include a string [method] value.');
        }

        if (! is_string($payload['method'])) {
            throw SkirServerException::invalidRequest('Skir requests must include a string [method] value.');
        }

        return $payload['method'];
    }
}
