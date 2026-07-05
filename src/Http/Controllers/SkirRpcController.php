<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use LaravelSkir\Runtime\DenseJson;
use LaravelSkir\Runtime\Exceptions\SkirRuntimeException;
use LaravelSkir\Server\Exceptions\SkirServerException;
use LaravelSkir\Server\RequestContext;
use LaravelSkir\Server\SkirServer;
use Symfony\Component\HttpFoundation\Response;

final readonly class SkirRpcController
{
    public function __construct(
        private SkirServer $server,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = $this->payloadFromRequest($request);
            $method = $this->methodFromPayload($payload);
            $procedure = $this->server->procedure($method);
            $decodedRequest = DenseJson::decode($procedure->descriptor->requestType, $payload['request'] ?? 0);
            $result = $procedure->invoke($decodedRequest, new RequestContext($request, $procedure->descriptor));

            return response()->json(
                DenseJson::encode($procedure->descriptor->responseType, $result),
                Response::HTTP_OK,
            );
        } catch (SkirServerException $exception) {
            return $exception->toResponse();
        } catch (SkirRuntimeException $exception) {
            return SkirServerException::invalidRequest($exception->getMessage())->toResponse();
        }
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
