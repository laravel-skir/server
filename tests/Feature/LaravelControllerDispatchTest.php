<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Attributes\Controllers\Middleware as ControllerMiddlewareAttribute;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\Field;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\Codecs\SkirCodecs;
use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\Facades\Skir;
use Skir\Server\RegisteredProcedure;
use Skir\Server\RequestContext;
use Skir\Server\SkirContext;
use Skir\Server\SkirServer;
use Skir\Server\Tests\TestCase;

final class LaravelControllerDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'Native Skir']);
        NativeDispatchRecorder::reset();
        NativePayload::$hydrations = 0;
        NativeDependency::$resolutions = 0;
        NativeDispatchController::$callActions = 0;
        NativeDispatchController::$contextObjectId = null;
    }

    #[Test]
    public function it_prepares_laravel_controller_middleware_and_dispatches_native_parameters(): void
    {
        $route = $this->registerController('/native-dispatch', NativeDispatchController::class);
        $server = $this->serverFromRoute($route);
        $context = $this->contextForRoute($route, NativeDispatchMethod::Update);
        $prepared = $server->procedure('Update')->prepare(['name' => 'Maxim'], $context);

        $result = (new Pipeline(app()))
            ->send($context->request)
            ->through($prepared->middleware)
            ->then(fn (Request $request): mixed => $prepared->invoke());

        $this->assertSame(['class', 'method'], NativeDispatchRecorder::$events);
        $this->assertSame(1, NativePayload::$hydrations);
        $this->assertSame(1, NativeDependency::$resolutions);
        $this->assertSame(1, NativeDispatchController::$callActions);
        $this->assertSame(spl_object_id($context), NativeDispatchController::$contextObjectId);
        $this->assertSame(
            ['name' => 'Maxim:container:Native Skir'],
            $server->codec()->encodeResponse(NativeDispatchMethod::Update->descriptor(), $result),
        );
    }

    #[Test]
    public function it_normalizes_and_encodes_results_through_the_existing_rpc_route(): void
    {
        $this->registerController('/native-route-response', NativeDispatchController::class);

        $this
            ->postJson('/native-route-response', [
                'method' => 'Update',
                'request' => ['name' => 'Maxim'],
            ])
            ->assertOk()
            ->assertExactJson(['name' => 'Maxim:container:Native Skir']);

        $this->assertSame(1, NativePayload::$hydrations);
        $this->assertSame(1, NativeDependency::$resolutions);
        $this->assertSame(1, NativeDispatchController::$callActions);
    }

    #[Test]
    public function it_discovers_and_runs_has_middleware_declarations(): void
    {
        $route = $this->registerController('/has-middleware', HasMiddlewareDispatchController::class);
        $server = $this->serverFromRoute($route);
        $context = $this->contextForRoute($route, NativeDispatchMethod::HasMiddleware);
        $prepared = $server->procedure('HasMiddleware')->prepare(null, $context);

        $result = (new Pipeline(app()))
            ->send($context->request)
            ->through($prepared->middleware)
            ->then(fn (Request $request): mixed => $prepared->invoke());

        $this->assertSame(['has-middleware'], NativeDispatchRecorder::$events);
        $this->assertSame('Native Skir', $result);
    }

    #[Test]
    public function it_prepares_manual_procedures_without_middleware_and_keeps_invoke_compatibility(): void
    {
        $descriptor = NativeDispatchMethod::HasMiddleware->descriptor();
        $context = new RequestContext(Request::create('/manual-procedure'), $descriptor);
        $procedure = new RegisteredProcedure(
            $descriptor,
            fn (string $request, SkirContext $context): string => "{$request}:{$context->method->name}",
        );

        $prepared = $procedure->prepare('prepared', $context);

        $this->assertSame([], $prepared->middleware);
        $this->assertSame('prepared:HasMiddleware', $prepared->invoke());
        $this->assertSame('compatible:HasMiddleware', $procedure->invoke('compatible', $context));
    }

    /** @param class-string $controller */
    private function registerController(string $uri, string $controller): LaravelRoute
    {
        return Route::skirRpc($uri, [
            Skir::controller($controller),
        ], SkirCodecs::standardJson());
    }

    private function serverFromRoute(LaravelRoute $route): SkirServer
    {
        $server = $route->defaults['skirServer'] ?? null;

        $this->assertInstanceOf(SkirServer::class, $server);

        return $server;
    }

    private function contextForRoute(LaravelRoute $route, NativeDispatchMethod $method): RequestContext
    {
        $request = Request::create($route->uri(), 'POST');
        $route->parameters = [];
        $request->setRouteResolver(fn (): LaravelRoute => $route);

        return new RequestContext($request, $method->descriptor());
    }
}

enum NativeDispatchMethod implements SkirMethodReference
{
    case Update;
    case HasMiddleware;

    public function descriptor(): MethodDescriptor
    {
        return match ($this) {
            self::Update => new MethodDescriptor(
                'Update',
                6001,
                Type::struct([Field::value('name', 0, Type::string())]),
                Type::struct([Field::value('name', 0, Type::string())]),
            ),
            self::HasMiddleware => new MethodDescriptor(
                'HasMiddleware',
                6002,
                Type::optional(Type::struct([])),
                Type::string(),
            ),
        };
    }
}

#[ControllerMiddlewareAttribute(NativeClassMiddleware::class)]
final class NativeDispatchController extends Controller
{
    public static int $callActions = 0;

    public static ?int $contextObjectId = null;

    #[SkirMethod(NativeDispatchMethod::Update)]
    #[ControllerMiddlewareAttribute(NativeMethodMiddleware::class)]
    public function update(
        NativePayload $request,
        SkirContext $context,
        NativeDependency $dependency,
        #[Config('app.name')] string $applicationName,
    ): NativePayload {
        self::$contextObjectId = spl_object_id($context);

        return new NativePayload("{$request->name}:{$dependency->value}:{$applicationName}");
    }

    public function callAction($method, $parameters): mixed
    {
        self::$callActions++;

        return parent::callAction($method, $parameters);
    }
}

final class HasMiddlewareDispatchController implements HasMiddleware
{
    /** @return array<int, class-string> */
    public static function middleware(): array
    {
        return [NativeHasMiddleware::class];
    }

    #[SkirMethod(NativeDispatchMethod::HasMiddleware)]
    public function dispatch(#[Config('app.name')] string $applicationName): string
    {
        return $applicationName;
    }
}

final class NativePayload
{
    public static int $hydrations = 0;

    public function __construct(public string $name) {}

    /** @param array{name: string} $payload */
    public static function makeFromSkirPayload(array $payload): self
    {
        self::$hydrations++;

        return new self($payload['name']);
    }

    /** @return array{name: string} */
    public function toSkirArray(): array
    {
        return ['name' => $this->name];
    }
}

final class NativeDependency
{
    public static int $resolutions = 0;

    public string $value;

    public function __construct()
    {
        self::$resolutions++;
        $this->value = 'container';
    }
}

final class NativeClassMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        NativeDispatchRecorder::$events[] = 'class';

        return $next($request);
    }
}

final class NativeMethodMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        NativeDispatchRecorder::$events[] = 'method';

        return $next($request);
    }
}

final class NativeHasMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        NativeDispatchRecorder::$events[] = 'has-middleware';

        return $next($request);
    }
}

final class NativeDispatchRecorder
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }
}
