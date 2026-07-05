<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Http\Controllers;

use Illuminate\Http\Request;
use JsonException;
use LaravelSkir\Runtime\Exceptions\SkirRuntimeException;
use LaravelSkir\Server\Exceptions\SkirServerException;
use LaravelSkir\Server\RequestContext;
use LaravelSkir\Server\SkirServer;
use LaravelSkir\Server\Studio\StudioRenderer;
use Symfony\Component\HttpFoundation\Response;

final readonly class SkirRpcController
{
    public function __construct(
        private SkirServer $server,
        private StudioRenderer $studioRenderer,
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

            $payload = $this->payloadFromRequest($request);
            $method = $this->methodFromPayload($payload);
            $procedure = $server->procedure($method);
            $decodedRequest = $server->codec()->decodeRequest($procedure->descriptor, $payload['request'] ?? 0);
            $result = $procedure->invoke($decodedRequest, new RequestContext($request, $procedure->descriptor));

            return response()->json(
                $server->codec()->encodeResponse($procedure->descriptor, $result),
                Response::HTTP_OK,
            );
        } catch (SkirServerException $exception) {
            return $exception->toResponse();
        } catch (SkirRuntimeException $exception) {
            return SkirServerException::invalidRequest($exception->getMessage())->toResponse();
        }
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
        return $request->isMethod('GET') && $request->query->has('studio');
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
    private function payloadFromRequest(Request $request): array
    {
        if ($request->isMethod('GET')) {
            return $this->payloadFromQueryString($request);
        }

        $payload = $request->json()->all();

        if (! is_array($payload)) {
            throw SkirServerException::invalidRequest('Skir requests must be JSON objects.');
        }

        return $payload;
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
