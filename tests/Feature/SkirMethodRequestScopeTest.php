<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Skir\Server\Http\Requests\SkirMethodRequestScope;
use Skir\Server\Tests\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\InputBag;

final class SkirMethodRequestScopeTest extends TestCase
{
    #[Test]
    public function it_scopes_the_laravel_request_to_the_decoded_method_payload(): void
    {
        $rawContent = '{"method":"RenameUser","request":{"email":"maxim@example.com","name":"Maxim"}}';
        $decodedPayload = [
            'email' => 'maxim@example.com',
            'name' => 'Maxim',
        ];
        $transportFile = UploadedFile::fake()->create('transport.txt', 1, 'text/plain');
        $transportRequest = Request::create(
            '/skir?method=QueryMethod&queryOnly=transport',
            'POST',
            ['staleInput' => 'transport'],
            ['transport-cookie' => 'cookie-value'],
            ['transportFile' => $transportFile],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer token',
                'HTTP_X_SKIR_TRACE' => 'trace-123',
            ],
            $rawContent,
        );
        $transportEnvelope = [
            'method' => 'RenameUser',
            'request' => $decodedPayload,
            'staleInput' => 'transport',
        ];
        $transportRequest->setJson(new InputBag($transportEnvelope));

        $route = new stdClass;
        $user = new GenericUser(['id' => 42]);
        $session = new Store('skir-test', new ArraySessionHandler(120));
        $session->put('tenant', 'acme');
        $this->app->instance('request', $transportRequest);
        $this->be($user);
        $transportRequest->setRouteResolver(static fn (): object => $route);
        $transportRequest->setLaravelSession($session);

        $result = (new SkirMethodRequestScope($this->app))->run(
            $transportRequest,
            $decodedPayload,
            function (Request $methodRequest, mixed $payload) use ($decodedPayload, $rawContent, $route, $session, $user): string {
                self::assertSame($methodRequest, app('request'));
                self::assertSame($decodedPayload, $payload);
                self::assertSame($decodedPayload, $methodRequest->all());
                self::assertSame($decodedPayload, $methodRequest->input());
                self::assertSame([], $methodRequest->query->all());
                self::assertSame([], $methodRequest->request->all());
                self::assertSame([], $methodRequest->files->all());
                self::assertSame($decodedPayload, $methodRequest->json()->all());
                self::assertArrayNotHasKey('method', $methodRequest->json()->all());
                self::assertArrayNotHasKey('request', $methodRequest->json()->all());
                self::assertArrayNotHasKey('staleInput', $methodRequest->json()->all());
                self::assertSame('Bearer token', $methodRequest->header('Authorization'));
                self::assertSame('trace-123', $methodRequest->header('X-Skir-Trace'));
                self::assertSame('cookie-value', $methodRequest->cookies->get('transport-cookie'));
                self::assertSame($rawContent, $methodRequest->getContent());
                self::assertSame($route, $methodRequest->route());
                self::assertSame($user, $methodRequest->user());
                self::assertSame($session, $methodRequest->session());
                self::assertSame('acme', $methodRequest->session()->get('tenant'));

                return 'dispatched';
            },
        );

        $this->assertSame('dispatched', $result);
        $this->assertSame($transportRequest, app('request'));
        $this->assertSame([
            'method' => 'QueryMethod',
            'queryOnly' => 'transport',
        ], $transportRequest->query->all());
        $this->assertSame(['staleInput' => 'transport'], $transportRequest->request->all());
        $this->assertSame(['transportFile' => $transportFile], $transportRequest->files->all());
        $this->assertSame($transportEnvelope, $transportRequest->json()->all());
        $this->assertSame('Bearer token', $transportRequest->header('Authorization'));
        $this->assertSame($rawContent, $transportRequest->getContent());
        $this->assertSame($route, $transportRequest->route());
        $this->assertSame($user, $transportRequest->user());
        $this->assertSame($session, $transportRequest->session());
    }

    #[Test]
    public function it_restores_the_transport_request_when_dispatch_throws(): void
    {
        $transportRequest = Request::create('/skir', 'POST');
        $this->app->instance('request', $transportRequest);
        $methodRequest = null;

        try {
            (new SkirMethodRequestScope($this->app))->run(
                $transportRequest,
                ['name' => 'Maxim'],
                function (Request $scopedRequest) use (&$methodRequest): never {
                    $methodRequest = $scopedRequest;

                    self::assertSame($scopedRequest, app('request'));

                    throw new RuntimeException('dispatch failed');
                },
            );

            self::fail('The dispatch exception was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('dispatch failed', $exception->getMessage());
        }

        $this->assertInstanceOf(Request::class, $methodRequest);
        $this->assertNotSame($transportRequest, $methodRequest);
        $this->assertSame($transportRequest, app('request'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function scalarAndNullPayloadProvider(): iterable
    {
        yield 'scalar payload' => ['decoded scalar'];
        yield 'null payload' => [null];
    }

    #[Test]
    #[DataProvider('scalarAndNullPayloadProvider')]
    public function it_keeps_scalar_and_null_payloads_out_of_the_method_request_input(mixed $payload): void
    {
        $transportRequest = Request::create(
            '/skir?queryOnly=transport',
            'POST',
            ['staleInput' => 'transport'],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"method":"ScalarMethod","request":"transport envelope"}',
        );
        $transportRequest->setJson(new InputBag([
            'method' => 'ScalarMethod',
            'request' => 'transport envelope',
        ]));
        $this->app->instance('request', $transportRequest);

        (new SkirMethodRequestScope($this->app))->run(
            $transportRequest,
            $payload,
            function (Request $methodRequest, mixed $callbackPayload) use ($payload): void {
                self::assertSame($payload, $callbackPayload);
                self::assertSame($methodRequest, app('request'));
                self::assertSame([], $methodRequest->all());
                self::assertSame([], $methodRequest->input());
                self::assertSame([], $methodRequest->query->all());
                self::assertSame([], $methodRequest->request->all());
                self::assertSame([], $methodRequest->files->all());
                self::assertSame([], $methodRequest->json()->all());
            },
        );

        $this->assertSame($transportRequest, app('request'));
    }
}
