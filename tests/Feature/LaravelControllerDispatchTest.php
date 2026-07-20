<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Routing\Attributes\Controllers\Middleware as ControllerMiddlewareAttribute;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Skir\Runtime\Field;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\Codecs\SkirCodecs;
use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\Facades\Skir;
use Skir\Server\Http\Requests\SkirFormRequest;
use Skir\Server\RegisteredProcedure;
use Skir\Server\RequestContext;
use Skir\Server\SkirContext;
use Skir\Server\SkirServer;
use Skir\Server\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

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
        MiddlewareStateSkirRequest::reset();
        ResponsePostProcessingMiddleware::$receivedSymfonyResponse = false;
        RoutingPipelineController::$invocations = 0;

        app('router')->aliasMiddleware('test-auth', TestAuthenticationMiddleware::class);
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

    #[Test]
    public function controller_middleware_runs_before_form_request_preparation(): void
    {
        $this->registerController(
            '/middleware-state/{tenant}',
            MiddlewareStateController::class,
        );

        $this->assertSame(0, MiddlewareStateSkirRequest::$authorizations);
        $this->assertSame(0, MiddlewareStateSkirRequest::$ruleResolutions);

        $this
            ->postJson('/middleware-state/outer-tenant', [
                'method' => 'MiddlewareState',
                'request' => ['name' => 'Maxim'],
            ])
            ->assertOk()
            ->assertContent('"Maxim"');

        $this->assertSame(1, MiddlewareStateSkirRequest::$authorizations);
        $this->assertSame(1, MiddlewareStateSkirRequest::$ruleResolutions);
        $this->assertSame([
            'userId' => 42,
            'tenant' => 'middleware-tenant',
        ], MiddlewareStateSkirRequest::$authorizationContext);
    }

    #[Test]
    public function short_circuiting_controller_middleware_avoids_parameter_preparation(): void
    {
        $route = $this->registerController('/short-circuit', ShortCircuitController::class);
        $server = $this->serverFromRoute($route);
        $context = $this->contextForRoute($route, NativeDispatchMethod::ShortCircuit);

        $prepared = $server->procedure('ShortCircuit')->prepare(['name' => 'unused'], $context);

        $this->assertSame(0, NativePayload::$hydrations);

        $result = (new Pipeline(app()))
            ->send($context->request)
            ->through($prepared->middleware)
            ->then(fn (Request $request): mixed => $prepared->invoke());

        $this->assertSame('short-circuited', $result);
        $this->assertSame(0, NativePayload::$hydrations);
    }

    #[Test]
    public function method_middleware_protects_one_procedure_without_protecting_its_controller_sibling(): void
    {
        $this->registerController('/method-auth', MethodMiddlewareController::class);

        $this
            ->postJson('/method-auth', [
                'method' => 'Login',
                'request' => null,
            ])
            ->assertOk()
            ->assertContent('"logged-in"');

        $this
            ->postJson('/method-auth', [
                'method' => 'GetMe',
                'request' => null,
            ])
            ->assertUnauthorized();

        $this->be(new GenericUser(['id' => 42]));

        $this
            ->postJson('/method-auth', [
                'method' => 'GetMe',
                'request' => null,
            ])
            ->assertOk()
            ->assertContent('"42"');
    }

    #[Test]
    public function middleware_can_post_process_the_encoded_symfony_response(): void
    {
        $this->registerController('/post-processing', MethodMiddlewareController::class);

        $this
            ->postJson('/post-processing', [
                'method' => 'PostProcess',
                'request' => null,
            ])
            ->assertOk()
            ->assertHeader('X-Skir-Middleware', 'applied')
            ->assertContent('"encoded-first"');

        $this->assertTrue(ResponsePostProcessingMiddleware::$receivedSymfonyResponse);
    }

    #[Test]
    public function string_short_circuit_middleware_is_normalized_to_an_http_response(): void
    {
        $this->registerController('/string-short-circuit', RoutingPipelineController::class);

        $this
            ->postJson('/string-short-circuit', [
                'method' => 'StringShortCircuit',
                'request' => ['name' => 'unused'],
            ])
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertContent('short-circuited');

        $this->assertSame(0, RoutingPipelineController::$invocations);
        $this->assertSame(0, NativePayload::$hydrations);
    }

    #[Test]
    public function responsable_short_circuit_middleware_is_normalized_to_an_http_response(): void
    {
        $this->registerController('/responsable-short-circuit', RoutingPipelineController::class);

        $this
            ->postJson('/responsable-short-circuit', [
                'method' => 'ResponsableShortCircuit',
                'request' => ['name' => 'unused'],
            ])
            ->assertStatus(Response::HTTP_ACCEPTED)
            ->assertHeader('X-Skir-Responsable', 'applied')
            ->assertExactJson(['result' => 'short-circuited']);

        $this->assertSame(0, RoutingPipelineController::$invocations);
        $this->assertSame(0, NativePayload::$hydrations);
    }

    #[Test]
    public function post_processing_middleware_wraps_dependency_validation_errors(): void
    {
        $this->registerController('/pipeline-validation', RoutingPipelineController::class);

        $this
            ->postJson('/pipeline-validation', [
                'method' => 'ValidationFailure',
                'request' => ['name' => ''],
            ])
            ->assertUnprocessable()
            ->assertHeader('X-Skir-Middleware', 'applied')
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_validation_failed',
                    'message' => 'The given data was invalid.',
                    'details' => [
                        'name' => ['The name field is required.'],
                    ],
                ],
            ]);

        $this->assertSame(0, RoutingPipelineController::$invocations);
    }

    #[Test]
    public function post_processing_middleware_wraps_dependency_authorization_errors(): void
    {
        $this->registerController('/pipeline-authorization', RoutingPipelineController::class);

        $this
            ->postJson('/pipeline-authorization', [
                'method' => 'AuthorizationFailure',
                'request' => ['name' => 'Maxim'],
            ])
            ->assertForbidden()
            ->assertHeader('X-Skir-Middleware', 'applied')
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_authorization_failed',
                    'message' => 'This action is unauthorized.',
                ],
            ]);

        $this->assertSame(0, RoutingPipelineController::$invocations);
    }

    #[Test]
    public function package_exceptions_keep_their_skir_envelope_inside_the_middleware_pipeline(): void
    {
        $this->registerController('/pipeline-package-error', RoutingPipelineController::class);

        $this
            ->postJson('/pipeline-package-error', [
                'method' => 'PackageFailure',
                'request' => null,
            ])
            ->assertUnprocessable()
            ->assertHeader('X-Skir-Middleware', 'applied')
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_invalid_request',
                    'message' => 'Invalid request inside the procedure pipeline.',
                ],
            ]);
    }

    #[Test]
    public function codec_runtime_exceptions_keep_their_skir_envelope_inside_the_middleware_pipeline(): void
    {
        Route::skirRpc('/pipeline-codec-error', [
            Skir::controller(RoutingPipelineController::class),
        ]);

        $this
            ->postJson('/pipeline-codec-error', [
                'method' => 'RuntimeCodecFailure',
                'request' => null,
            ])
            ->assertUnprocessable()
            ->assertHeader('X-Skir-Middleware', 'applied')
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_invalid_request',
                    'message' => 'Skir array values must be PHP arrays.',
                ],
            ]);
    }

    #[Test]
    public function unexpected_controller_exceptions_use_laravels_error_response_inside_the_middleware_pipeline(): void
    {
        config(['app.debug' => false]);
        $this->registerController('/pipeline-unexpected-error', RoutingPipelineController::class);

        $this
            ->postJson('/pipeline-unexpected-error', [
                'method' => 'UnexpectedFailure',
                'request' => null,
            ])
            ->assertInternalServerError()
            ->assertHeader('X-Skir-Middleware', 'applied')
            ->assertExactJson(['message' => 'Server Error']);
    }

    #[Test]
    public function without_middleware_skips_controller_middleware_attributes(): void
    {
        $this->withoutMiddleware();
        $this->registerController('/middleware-disabled', MethodMiddlewareController::class);

        $this
            ->postJson('/middleware-disabled', [
                'method' => 'PostProcess',
                'request' => null,
            ])
            ->assertOk()
            ->assertHeaderMissing('X-Skir-Middleware')
            ->assertContent('"encoded-first"');

        $this->assertFalse(ResponsePostProcessingMiddleware::$receivedSymfonyResponse);
    }

    #[Test]
    public function native_authorize_attributes_use_the_laravel_gate(): void
    {
        Gate::define(
            'view-account',
            static fn (?GenericUser $user): bool => $user?->getAuthIdentifier() === 42,
        );
        $this->registerController('/authorized-method', MethodMiddlewareController::class);

        $this
            ->postJson('/authorized-method', [
                'method' => 'ViewAccount',
                'request' => null,
            ])
            ->assertForbidden();

        $this->be(new GenericUser(['id' => 42]));

        $this
            ->postJson('/authorized-method', [
                'method' => 'ViewAccount',
                'request' => null,
            ])
            ->assertOk()
            ->assertContent('"account-visible"');
    }

    #[Test]
    public function studio_bypasses_procedure_middleware_while_calls_remain_protected(): void
    {
        $this->registerController('/protected-studio', MethodMiddlewareController::class)->studio();

        $this
            ->get('/protected-studio?studio')
            ->assertOk()
            ->assertSee('GetMe');

        $this
            ->postJson('/protected-studio', [
                'method' => 'GetMe',
                'request' => null,
            ])
            ->assertUnauthorized();
    }

    #[Test]
    public function nullable_markers_restore_null_by_parameter_position(): void
    {
        $route = $this->registerController(
            '/nullable-position/{routeStatus}',
            NullablePositionController::class,
        );
        $server = $this->serverFromRoute($route);
        $context = $this->contextForRoute(
            $route,
            NativeDispatchMethod::NullablePosition,
            ['routeStatus' => NativeNullableStatus::Active],
        );

        $prepared = $server->procedure('NullablePosition')->prepare(null, $context);

        $this->assertSame('null:active', $prepared->invoke());
    }

    #[Test]
    public function scalar_union_parameters_receive_the_decoded_request(): void
    {
        $route = $this->registerController('/scalar-union', ScalarUnionController::class);
        $server = $this->serverFromRoute($route);
        $context = $this->contextForRoute($route, NativeDispatchMethod::ScalarUnion);

        $prepared = $server->procedure('ScalarUnion')->prepare('union-value', $context);

        $this->assertSame('string:union-value', $prepared->invoke());
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

    /** @param array<string, string|object|null> $parameters */
    private function contextForRoute(
        LaravelRoute $route,
        NativeDispatchMethod $method,
        array $parameters = [],
    ): RequestContext {
        $request = Request::create($route->uri(), 'POST');
        $route->parameters = $parameters;
        $request->setRouteResolver(fn (): LaravelRoute => $route);

        return new RequestContext($request, $method->descriptor());
    }
}

enum NativeDispatchMethod implements SkirMethodReference
{
    case Update;
    case HasMiddleware;
    case MiddlewareState;
    case ShortCircuit;
    case NullablePosition;
    case ScalarUnion;
    case Login;
    case GetMe;
    case PostProcess;
    case ViewAccount;
    case StringShortCircuit;
    case ResponsableShortCircuit;
    case ValidationFailure;
    case AuthorizationFailure;
    case PackageFailure;
    case UnexpectedFailure;
    case RuntimeCodecFailure;

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
            self::MiddlewareState => new MethodDescriptor(
                'MiddlewareState',
                6003,
                Type::struct([Field::value('name', 0, Type::string())]),
                Type::string(),
            ),
            self::ShortCircuit => new MethodDescriptor(
                'ShortCircuit',
                6004,
                Type::struct([Field::value('name', 0, Type::string())]),
                Type::string(),
            ),
            self::NullablePosition => new MethodDescriptor(
                'NullablePosition',
                6005,
                Type::optional(Type::string()),
                Type::string(),
            ),
            self::ScalarUnion => new MethodDescriptor(
                'ScalarUnion',
                6006,
                Type::string(),
                Type::string(),
            ),
            self::Login => new MethodDescriptor(
                'Login',
                6007,
                Type::optional(Type::struct([])),
                Type::string(),
            ),
            self::GetMe => new MethodDescriptor(
                'GetMe',
                6008,
                Type::optional(Type::struct([])),
                Type::string(),
            ),
            self::PostProcess => new MethodDescriptor(
                'PostProcess',
                6009,
                Type::optional(Type::struct([])),
                Type::string(),
            ),
            self::ViewAccount => new MethodDescriptor(
                'ViewAccount',
                6010,
                Type::optional(Type::struct([])),
                Type::string(),
            ),
            self::StringShortCircuit => new MethodDescriptor(
                'StringShortCircuit',
                6011,
                Type::struct([Field::value('name', 0, Type::string())]),
                Type::string(),
            ),
            self::ResponsableShortCircuit => new MethodDescriptor(
                'ResponsableShortCircuit',
                6012,
                Type::struct([Field::value('name', 0, Type::string())]),
                Type::string(),
            ),
            self::ValidationFailure => new MethodDescriptor(
                'ValidationFailure',
                6013,
                Type::struct([Field::value('name', 0, Type::string())]),
                Type::string(),
            ),
            self::AuthorizationFailure => new MethodDescriptor(
                'AuthorizationFailure',
                6014,
                Type::struct([Field::value('name', 0, Type::string())]),
                Type::string(),
            ),
            self::PackageFailure => new MethodDescriptor(
                'PackageFailure',
                6015,
                Type::optional(Type::struct([])),
                Type::string(),
            ),
            self::UnexpectedFailure => new MethodDescriptor(
                'UnexpectedFailure',
                6016,
                Type::optional(Type::struct([])),
                Type::string(),
            ),
            self::RuntimeCodecFailure => new MethodDescriptor(
                'RuntimeCodecFailure',
                6017,
                Type::optional(Type::struct([])),
                Type::array(Type::string()),
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

#[ControllerMiddlewareAttribute(MiddlewareStateInitializer::class)]
final class MiddlewareStateController
{
    #[SkirMethod(NativeDispatchMethod::MiddlewareState)]
    public function dispatch(MiddlewareStateSkirRequest $request): string
    {
        return (string) $request->input('name');
    }
}

#[ControllerMiddlewareAttribute(ShortCircuitMiddleware::class)]
final class ShortCircuitController
{
    #[SkirMethod(NativeDispatchMethod::ShortCircuit)]
    public function dispatch(NativePayload $request): string
    {
        return $request->name;
    }
}

final class NullablePositionController
{
    #[SkirMethod(NativeDispatchMethod::NullablePosition)]
    public function dispatch(
        ?NativeNullableStatus $request,
        NativeNullableStatus $routeStatus,
    ): string {
        $requestValue = $request?->value ?? 'null';

        return "{$requestValue}:{$routeStatus->value}";
    }
}

final class ScalarUnionController
{
    #[SkirMethod(NativeDispatchMethod::ScalarUnion)]
    public function dispatch(string|int $request): string
    {
        return get_debug_type($request).":{$request}";
    }
}

final class MethodMiddlewareController
{
    #[SkirMethod(NativeDispatchMethod::Login)]
    public function login(): string
    {
        return 'logged-in';
    }

    #[SkirMethod(NativeDispatchMethod::GetMe)]
    #[ControllerMiddlewareAttribute('test-auth')]
    public function getMe(SkirContext $context): string
    {
        return (string) $context->request->user()?->getAuthIdentifier();
    }

    #[SkirMethod(NativeDispatchMethod::PostProcess)]
    #[ControllerMiddlewareAttribute(ResponsePostProcessingMiddleware::class)]
    public function postProcess(): string
    {
        return 'encoded-first';
    }

    #[SkirMethod(NativeDispatchMethod::ViewAccount)]
    #[Authorize('view-account')]
    public function viewAccount(): string
    {
        return 'account-visible';
    }
}

final class RoutingPipelineController
{
    public static int $invocations = 0;

    #[SkirMethod(NativeDispatchMethod::StringShortCircuit)]
    #[ControllerMiddlewareAttribute(StringShortCircuitMiddleware::class)]
    public function stringShortCircuit(NativePayload $request): string
    {
        self::$invocations++;

        return $request->name;
    }

    #[SkirMethod(NativeDispatchMethod::ResponsableShortCircuit)]
    #[ControllerMiddlewareAttribute(ResponsableShortCircuitMiddleware::class)]
    public function responsableShortCircuit(NativePayload $request): string
    {
        self::$invocations++;

        return $request->name;
    }

    #[SkirMethod(NativeDispatchMethod::ValidationFailure)]
    #[ControllerMiddlewareAttribute(ResponsePostProcessingMiddleware::class)]
    public function validationFailure(PipelineValidationSkirRequest $request): string
    {
        self::$invocations++;

        return $request->skir()->name;
    }

    #[SkirMethod(NativeDispatchMethod::AuthorizationFailure)]
    #[ControllerMiddlewareAttribute(ResponsePostProcessingMiddleware::class)]
    public function authorizationFailure(PipelineAuthorizationSkirRequest $request): string
    {
        self::$invocations++;

        return $request->skir()->name;
    }

    #[SkirMethod(NativeDispatchMethod::PackageFailure)]
    #[ControllerMiddlewareAttribute(ResponsePostProcessingMiddleware::class)]
    public function packageFailure(): never
    {
        throw SkirServerException::invalidRequest('Invalid request inside the procedure pipeline.');
    }

    #[SkirMethod(NativeDispatchMethod::UnexpectedFailure)]
    #[ControllerMiddlewareAttribute(ResponsePostProcessingMiddleware::class)]
    public function unexpectedFailure(): never
    {
        throw new RuntimeException('Unexpected procedure failure.');
    }

    #[SkirMethod(NativeDispatchMethod::RuntimeCodecFailure)]
    #[ControllerMiddlewareAttribute(ResponsePostProcessingMiddleware::class)]
    public function runtimeCodecFailure(): string
    {
        return 'not-an-array';
    }
}

final class MiddlewareStateSkirRequest extends SkirFormRequest
{
    /** @var array{userId?: mixed, tenant?: mixed} */
    public static array $authorizationContext = [];

    public static int $authorizations = 0;

    public static int $ruleResolutions = 0;

    public function authorize(): bool
    {
        self::$authorizations++;
        self::$authorizationContext = [
            'userId' => $this->user()?->getAuthIdentifier(),
            'tenant' => $this->route('tenant'),
        ];

        return self::$authorizationContext === [
            'userId' => 42,
            'tenant' => 'middleware-tenant',
        ];
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        self::$ruleResolutions++;

        return [
            'name' => ['required', 'string'],
        ];
    }

    public static function reset(): void
    {
        self::$authorizationContext = [];
        self::$authorizations = 0;
        self::$ruleResolutions = 0;
    }

    /** @return class-string<NativePayload> */
    protected function skirClass(): string
    {
        return NativePayload::class;
    }
}

final class PipelineValidationSkirRequest extends SkirFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }

    /** @return class-string<NativePayload> */
    protected function skirClass(): string
    {
        return NativePayload::class;
    }
}

final class PipelineAuthorizationSkirRequest extends SkirFormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }

    /** @return class-string<NativePayload> */
    protected function skirClass(): string
    {
        return NativePayload::class;
    }
}

enum NativeNullableStatus: string
{
    case Active = 'active';

    public static function fromSkirValue(string $value): self
    {
        return self::from($value);
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

final class MiddlewareStateInitializer
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->setUserResolver(fn (): GenericUser => new GenericUser(['id' => 42]));
        $request->route()?->setParameter('tenant', 'middleware-tenant');

        return $next($request);
    }
}

final class ShortCircuitMiddleware
{
    public function handle(Request $request, Closure $next): string
    {
        return 'short-circuited';
    }
}

final class StringShortCircuitMiddleware
{
    public function handle(Request $request, Closure $next): string
    {
        return 'short-circuited';
    }
}

final class ResponsableShortCircuitMiddleware
{
    public function handle(Request $request, Closure $next): Responsable
    {
        return new PipelineShortCircuitResponsable;
    }
}

final class PipelineShortCircuitResponsable implements Responsable
{
    public function toResponse($request): Response
    {
        return response()
            ->json(['result' => 'short-circuited'], Response::HTTP_ACCEPTED)
            ->header('X-Skir-Responsable', 'applied');
    }
}

final class TestAuthenticationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}

final class ResponsePostProcessingMiddleware
{
    public static bool $receivedSymfonyResponse = false;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        self::$receivedSymfonyResponse = $response instanceof Response;

        if (! $response instanceof Response) {
            throw new RuntimeException('Procedure middleware must receive an encoded Symfony response.');
        }

        $response->headers->set('X-Skir-Middleware', 'applied');

        return $response;
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
