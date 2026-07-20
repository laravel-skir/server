<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Codecs\SkirCodecs;
use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\Facades\Skir;
use Skir\Server\Http\Requests\SkirFormRequest;
use Skir\Server\Http\Requests\SkirMethodRequestScope;
use Skir\Server\RequestContext;
use Skir\Server\SkirContext;
use Skir\Server\SkirServer;
use Skir\Server\Tests\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use TypeError;

final class NullableControllerRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    }

    #[Test]
    public function nullable_standard_generated_requests_receive_null_without_hydration(): void
    {
        $this->registerRoute(NullableRequestMethod::Standard, NullableStandardController::class);

        NullableStandardRequest::$hydrations = 0;

        $this->postNullRequest('/nullable-standard', 'Standard')
            ->assertOk()
            ->assertExactJson(['received' => null]);

        $this->assertSame(0, NullableStandardRequest::$hydrations);
    }

    #[Test]
    public function nullable_laravel_data_requests_receive_null_without_hydration(): void
    {
        $this->registerRoute(NullableRequestMethod::Data, NullableDataController::class);

        NullableDataRequest::$hydrations = 0;

        $this->postNullRequest('/nullable-data', 'Data')
            ->assertOk()
            ->assertExactJson(['received' => null]);

        $this->assertSame(0, NullableDataRequest::$hydrations);
    }

    #[Test]
    public function nullable_enums_receive_null_without_hydration(): void
    {
        $this->registerRoute(NullableRequestMethod::EnumValue, NullableEnumController::class);

        NullableRequestStatusHydrations::$count = 0;

        $this->postNullRequest('/nullable-enum', 'EnumValue')
            ->assertOk()
            ->assertExactJson(['received' => null]);

        $this->assertSame(0, NullableRequestStatusHydrations::$count);
    }

    #[Test]
    public function nullable_form_requests_authorize_null_without_validation_or_hydration(): void
    {
        $this->registerRoute(NullableRequestMethod::FormRequest, NullableFormRequestController::class);

        NullableGeneratedFormRequest::$authorizations = 0;
        NullableGeneratedFormRequest::$instances = 0;
        NullableGeneratedFormRequest::$preparations = 0;
        NullableGeneratedFormRequest::$ruleResolutions = 0;
        NullableGeneratedFormRequest::$skirClassResolutions = 0;
        NullableFormPayload::$hydrations = 0;
        NullableGeneratedFormRequest::$authorizationContext = [];
        $this->be(new GenericUser(['id' => 42]));

        $this->withSession(['skir_session' => 'session-123'])
            ->withHeader('X-Skir-Trace', 'trace-123');

        $this->postNullRequest('/nullable-form-request/acme', 'FormRequest')
            ->assertOk()
            ->assertExactJson(['received' => null]);

        $this->assertSame(1, NullableGeneratedFormRequest::$authorizations);
        $this->assertSame(1, NullableGeneratedFormRequest::$instances);
        $this->assertSame(1, NullableGeneratedFormRequest::$preparations);
        $this->assertSame(0, NullableGeneratedFormRequest::$ruleResolutions);
        $this->assertSame(0, NullableGeneratedFormRequest::$skirClassResolutions);
        $this->assertSame(0, NullableFormPayload::$hydrations);
        $this->assertSame([
            'userId' => 42,
            'tenant' => 'acme',
            'trace' => 'trace-123',
            'session' => 'session-123',
            'input' => [],
        ], NullableGeneratedFormRequest::$authorizationContext);
    }

    #[Test]
    public function generated_default_deny_nullable_form_requests_reject_null(): void
    {
        $this->registerRoute(NullableRequestMethod::DefaultDenyFormRequest, DefaultDenyNullableFormController::class);

        DefaultDenyNullableFormRequest::$authorizations = 0;
        DefaultDenyNullableFormRequest::$ruleResolutions = 0;
        DefaultDenyNullableFormController::$invocations = 0;

        $this->postNullRequest('/default-deny-form-request', 'DefaultDenyFormRequest')
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_authorization_failed',
                    'message' => 'This action is unauthorized.',
                ],
            ]);

        $this->assertSame(1, DefaultDenyNullableFormRequest::$authorizations);
        $this->assertSame(0, DefaultDenyNullableFormRequest::$ruleResolutions);
        $this->assertSame(0, DefaultDenyNullableFormController::$invocations);
    }

    #[Test]
    public function nullable_form_requests_preserve_custom_failed_authorization_behavior(): void
    {
        $this->registerRoute(NullableRequestMethod::CustomDenyFormRequest, CustomDenyNullableFormController::class);

        CustomDenyNullableFormRequest::$failedAuthorizations = 0;
        CustomDenyNullableFormController::$invocations = 0;

        $this->postNullRequest('/custom-deny-form-request', 'CustomDenyFormRequest')
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_invalid_request',
                    'message' => 'Custom nullable authorization failure.',
                ],
            ]);

        $this->assertSame(1, CustomDenyNullableFormRequest::$failedAuthorizations);
        $this->assertSame(0, CustomDenyNullableFormController::$invocations);
    }

    #[Test]
    public function nullable_laravel_form_requests_must_extend_the_skir_form_request(): void
    {
        $this->registerRoute(NullableRequestMethod::LaravelFormRequest, NullableLaravelFormController::class);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must extend');

        $this->postNullRequest('/laravel-form-request', 'LaravelFormRequest');
    }

    #[Test]
    public function non_null_nullable_requests_still_hydrate_and_validate(): void
    {
        $this->registerRoute(NullableRequestMethod::Standard, NullableStandardController::class);
        $this->registerRoute(NullableRequestMethod::Data, NullableDataController::class);
        $this->registerRoute(NullableRequestMethod::EnumValue, NullableEnumController::class);
        NullableStandardRequest::$hydrations = 0;
        NullableDataRequest::$hydrations = 0;
        NullableRequestStatusHydrations::$count = 0;
        NullableGeneratedFormRequest::$authorizations = 0;
        NullableGeneratedFormRequest::$instances = 0;
        NullableGeneratedFormRequest::$preparations = 0;
        NullableGeneratedFormRequest::$ruleResolutions = 0;
        NullableGeneratedFormRequest::$skirClassResolutions = 0;
        NullableFormPayload::$hydrations = 0;

        $this->postJson('/nullable-standard', ['method' => 'Standard', 'request' => ['name' => 'standard']])
            ->assertOk()
            ->assertExactJson(['received' => 'standard']);
        $this->postJson('/nullable-data', ['method' => 'Data', 'request' => ['name' => 'data']])
            ->assertOk()
            ->assertExactJson(['received' => 'data']);
        $this->postJson('/nullable-enum', ['method' => 'EnumValue', 'request' => 'active'])
            ->assertOk()
            ->assertExactJson(['received' => 'active']);
        $this->assertSame(['received' => 'form'], $this->invokeNonNullFormRequest(['name' => 'form']));

        $this->assertSame(1, NullableStandardRequest::$hydrations);
        $this->assertSame(1, NullableDataRequest::$hydrations);
        $this->assertSame(1, NullableRequestStatusHydrations::$count);
        $this->assertSame(1, NullableGeneratedFormRequest::$authorizations);
        $this->assertSame(1, NullableGeneratedFormRequest::$instances);
        $this->assertSame(1, NullableGeneratedFormRequest::$preparations);
        $this->assertSame(1, NullableGeneratedFormRequest::$ruleResolutions);
        $this->assertSame(1, NullableGeneratedFormRequest::$skirClassResolutions);
        $this->assertSame(1, NullableFormPayload::$hydrations);
    }

    #[Test]
    public function nonnullable_null_requests_keep_their_existing_failures(): void
    {
        $this->registerRoute(NullableRequestMethod::NonnullableStandard, NonnullableStandardController::class);
        $this->registerRoute(NullableRequestMethod::NonnullableFormRequest, NonnullableFormRequestController::class);

        try {
            $this->withoutExceptionHandling()
                ->postNullRequest('/nonnullable-standard', 'NonnullableStandard');

            $this->fail('A nonnullable generated request must reject null.');
        } catch (TypeError $exception) {
            $this->assertStringContainsString('must be of type array, null given', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Skir Form Requests require a decoded array/object payload.');

        $this->postNullRequest('/nonnullable-form-request', 'NonnullableFormRequest');
    }

    #[Test]
    public function nonnullable_enum_null_requests_keep_their_existing_failure(): void
    {
        $this->registerRoute(NullableRequestMethod::NonnullableEnum, NonnullableEnumController::class);

        $this->withoutExceptionHandling();
        $this->expectException(TypeError::class);

        $this->postNullRequest('/nonnullable-enum', 'NonnullableEnum');
    }

    #[Test]
    public function nonnullable_laravel_data_null_requests_keep_their_existing_failure(): void
    {
        $this->registerRoute(NullableRequestMethod::NonnullableData, NonnullableDataController::class);

        $this->withoutExceptionHandling();
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('must be of type array, null given');

        $this->postNullRequest('/nonnullable-data', 'NonnullableData');
    }

    #[Test]
    public function context_only_methods_are_not_given_a_null_payload_argument(): void
    {
        $this->registerRoute(NullableRequestMethod::ContextOnly, ContextOnlyController::class);

        $this->postNullRequest('/context-only', 'ContextOnly')
            ->assertOk()
            ->assertExactJson(['method' => 'ContextOnly']);
    }

    private function registerRoute(
        NullableRequestMethod $method,
        string $controller,
    ): void {
        $route = Route::skirRpc($this->routePath($method), [
            Skir::method($method, $controller),
        ], SkirCodecs::standardJson());

        if ($method === NullableRequestMethod::FormRequest) {
            $route->middleware('web');
        }
    }

    private function postNullRequest(string $uri, string $method): TestResponse
    {
        return $this->postJson($uri, [
            'method' => $method,
            'request' => null,
        ]);
    }

    /** @param array<string, mixed> $decodedPayload */
    private function invokeNonNullFormRequest(array $decodedPayload): mixed
    {
        $route = Route::skirRpc('/nullable-form-request/{tenant}', [
            Skir::method(NullableRequestMethod::FormRequest, NullableFormRequestController::class),
        ], SkirCodecs::standardJson());
        $route->parameters = ['tenant' => 'acme'];
        $transportRequest = Request::create(
            '/nullable-form-request/acme',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"method":"FormRequest","request":{"name":"outer"}}',
        );
        $transportRequest->setJson(new InputBag([
            'method' => 'FormRequest',
            'request' => ['name' => 'outer'],
        ]));
        $transportRequest->setRouteResolver(static fn (): LaravelRoute => $route);
        $transportRequest->setLaravelSession(new Store('nullable-form-request', new ArraySessionHandler(120)));
        $this->app->instance('request', $transportRequest);

        $server = $route->defaults['skirServer'] ?? null;
        $this->assertInstanceOf(SkirServer::class, $server);
        $context = new RequestContext($transportRequest, NullableRequestMethod::FormRequest->descriptor());

        return app(SkirMethodRequestScope::class)->run(
            $transportRequest,
            $decodedPayload,
            static fn (): mixed => $server
                ->procedure('FormRequest')
                ->prepare($decodedPayload, $context)
                ->invoke(),
        );
    }

    private function routePath(NullableRequestMethod $method): string
    {
        return match ($method) {
            NullableRequestMethod::Standard => '/nullable-standard',
            NullableRequestMethod::Data => '/nullable-data',
            NullableRequestMethod::EnumValue => '/nullable-enum',
            NullableRequestMethod::FormRequest => '/nullable-form-request/{tenant}',
            NullableRequestMethod::DefaultDenyFormRequest => '/default-deny-form-request',
            NullableRequestMethod::CustomDenyFormRequest => '/custom-deny-form-request',
            NullableRequestMethod::LaravelFormRequest => '/laravel-form-request',
            NullableRequestMethod::NonnullableStandard => '/nonnullable-standard',
            NullableRequestMethod::NonnullableFormRequest => '/nonnullable-form-request',
            NullableRequestMethod::NonnullableEnum => '/nonnullable-enum',
            NullableRequestMethod::NonnullableData => '/nonnullable-data',
            NullableRequestMethod::ContextOnly => '/context-only',
        };
    }
}

enum NullableRequestMethod implements SkirMethodReference
{
    case Standard;
    case Data;
    case EnumValue;
    case FormRequest;
    case DefaultDenyFormRequest;
    case CustomDenyFormRequest;
    case LaravelFormRequest;
    case NonnullableStandard;
    case NonnullableFormRequest;
    case NonnullableEnum;
    case NonnullableData;
    case ContextOnly;

    public function descriptor(): MethodDescriptor
    {
        $requestType = match ($this) {
            self::NonnullableStandard,
            self::NonnullableFormRequest,
            self::NonnullableEnum,
            self::NonnullableData => Type::struct([]),
            self::ContextOnly => Type::struct([]),
            default => Type::optional(Type::struct([])),
        };

        return new MethodDescriptor($this->name, $this->value(), $requestType, Type::struct([]));
    }

    private function value(): int
    {
        return match ($this) {
            self::Standard => 1,
            self::Data => 2,
            self::EnumValue => 3,
            self::FormRequest => 4,
            self::NonnullableStandard => 5,
            self::NonnullableFormRequest => 6,
            self::ContextOnly => 7,
            self::NonnullableEnum => 8,
            self::NonnullableData => 9,
            self::DefaultDenyFormRequest => 10,
            self::CustomDenyFormRequest => 11,
            self::LaravelFormRequest => 12,
        };
    }
}

final class NullableStandardRequest
{
    public static int $hydrations = 0;

    public function __construct(public string $name) {}

    /** @param array{name: string} $payload */
    public static function makeFromSkirPayload(array $payload): self
    {
        self::$hydrations++;

        return new self($payload['name']);
    }
}

final class NullableDataRequest
{
    public static int $hydrations = 0;

    public function __construct(public string $name) {}

    /** @param array{name: string} $payload */
    public static function fromArray(array $payload): self
    {
        self::$hydrations++;

        return new self($payload['name']);
    }
}

enum NullableRequestStatus: string
{
    case Active = 'active';

    public static function fromSkirValue(string $payload): self
    {
        NullableRequestStatusHydrations::$count++;

        return self::from($payload);
    }
}

final class NullableRequestStatusHydrations
{
    public static int $count = 0;
}

final class NullableGeneratedFormRequest extends SkirFormRequest
{
    /** @var array<string, mixed> */
    public static array $authorizationContext = [];

    public static int $authorizations = 0;

    public static int $instances = 0;

    public static int $preparations = 0;

    public static int $ruleResolutions = 0;

    public static int $skirClassResolutions = 0;

    public function __construct()
    {
        self::$instances++;

        parent::__construct();
    }

    public function authorize(): bool
    {
        self::$authorizations++;
        self::$authorizationContext = [
            'userId' => $this->user()?->getAuthIdentifier(),
            'tenant' => $this->route('tenant'),
            'trace' => $this->header('X-Skir-Trace'),
            'session' => $this->session()->get('skir_session'),
            'input' => $this->all(),
        ];

        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        self::$ruleResolutions++;

        return ['name' => ['required', 'string']];
    }

    protected function prepareForValidation(): void
    {
        self::$preparations++;
    }

    /** @return class-string<NullableFormPayload> */
    protected function skirClass(): string
    {
        self::$skirClassResolutions++;

        return NullableFormPayload::class;
    }
}

final class NullableFormPayload
{
    public static int $hydrations = 0;

    public function __construct(public string $name) {}

    /** @param array{name: string} $payload */
    public static function makeFromSkirPayload(array $payload): self
    {
        self::$hydrations++;

        return new self($payload['name']);
    }
}

final class DefaultDenyNullableFormRequest extends SkirFormRequest
{
    public static int $authorizations = 0;

    public static int $ruleResolutions = 0;

    public function authorize(): bool
    {
        self::$authorizations++;

        return false;
    }

    public function rules(): array
    {
        self::$ruleResolutions++;

        return [];
    }

    protected function skirClass(): string
    {
        return NullableStandardRequest::class;
    }
}

final class CustomDenyNullableFormRequest extends SkirFormRequest
{
    public static int $failedAuthorizations = 0;

    public function authorize(): bool
    {
        return false;
    }

    protected function failedAuthorization(): void
    {
        self::$failedAuthorizations++;

        throw SkirServerException::invalidRequest('Custom nullable authorization failure.');
    }

    protected function skirClass(): string
    {
        return NullableStandardRequest::class;
    }
}

final class NullableLaravelFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}

final class NullableStandardController
{
    public function __invoke(?NullableStandardRequest $request): array
    {
        return ['received' => $request?->name];
    }
}

final class NullableDataController
{
    public function __invoke(?NullableDataRequest $request): array
    {
        return ['received' => $request?->name];
    }
}

final class NullableEnumController
{
    public function __invoke(?NullableRequestStatus $request): array
    {
        return ['received' => $request?->value];
    }
}

final class NullableFormRequestController
{
    public function __invoke(?NullableGeneratedFormRequest $request): array
    {
        if ($request === null) {
            return ['received' => null];
        }

        $payload = $request->skir();

        return ['received' => $payload->name];
    }
}

final class DefaultDenyNullableFormController
{
    public static int $invocations = 0;

    public function __invoke(?DefaultDenyNullableFormRequest $request): array
    {
        self::$invocations++;

        return ['received' => $request];
    }
}

final class CustomDenyNullableFormController
{
    public static int $invocations = 0;

    public function __invoke(?CustomDenyNullableFormRequest $request): array
    {
        self::$invocations++;

        return ['received' => $request];
    }
}

final class NullableLaravelFormController
{
    public function __invoke(?NullableLaravelFormRequest $request): array
    {
        return ['received' => $request];
    }
}

final class NonnullableStandardController
{
    public function __invoke(NullableStandardRequest $request): array
    {
        return ['received' => $request->name];
    }
}

final class NonnullableFormRequestController
{
    public function __invoke(NullableGeneratedFormRequest $request): array
    {
        return ['received' => $request->string('name')->toString()];
    }
}

final class NonnullableEnumController
{
    public function __invoke(NullableRequestStatus $request): array
    {
        return ['received' => $request->value];
    }
}

final class NonnullableDataController
{
    public function __invoke(NullableDataRequest $request): array
    {
        return ['received' => $request->name];
    }
}

final class ContextOnlyController
{
    public function __invoke(SkirContext $context): array
    {
        return ['method' => $context->method->name];
    }
}
