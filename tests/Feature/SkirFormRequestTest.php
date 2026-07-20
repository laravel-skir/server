<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\Field;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Codecs\SkirCodecs;
use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\Facades\Skir;
use Skir\Server\Http\Requests\SkirFormRequest;
use Skir\Server\Http\Requests\SkirMethodRequestScope;
use Skir\Server\Hydration\SkirPayloadHydrator;
use Skir\Server\RequestContext;
use Skir\Server\SkirContext;
use Skir\Server\SkirServer;
use Skir\Server\Tests\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class SkirFormRequestTest extends TestCase
{
    #[Test]
    public function ordinary_form_requests_run_the_native_laravel_lifecycle_from_the_decoded_bag(): void
    {
        NativeLifecycleFormRequest::reset();
        NativeFormRequestController::$invocations = 0;
        $this->app->afterResolving(
            NativeLifecycleFormRequest::class,
            static function (): void {
                NativeLifecycleFormRequest::$containerResolutions++;
            },
        );

        $result = $this->invokeWithinMethodRequestScope(
            NativeFormRequestController::class,
            [
                'id' => 42,
                'name' => '  Maxim  ',
                'metadata' => 'decoded',
            ],
        );

        $this->assertSame([
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'decoded',
        ], $result);
        $this->assertSame(1, NativeFormRequestController::$invocations);
        $this->assertSame(1, NativeLifecycleFormRequest::$containerResolutions);
        $this->assertSame(1, NativeLifecycleFormRequest::$authorizations);
        $this->assertSame(1, NativeLifecycleFormRequest::$ruleResolutions);
        $this->assertSame(1, NativeLifecycleFormRequest::$afterResolutions);
        $this->assertSame(1, NativeLifecycleFormRequest::$afterValidations);
        $this->assertSame([
            'id' => 42,
            'name' => '  Maxim  ',
            'metadata' => 'decoded',
        ], NativeLifecycleFormRequest::$initialInput);
        $this->assertSame([
            'tenant' => 'acme',
            'userId' => 42,
            'trace' => 'trace-123',
            'session' => 'session-123',
        ], NativeLifecycleFormRequest::$preparationContext);
        $this->assertSame([
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'decoded',
        ], NativeLifecycleFormRequest::$validatedInput);
    }

    #[Test]
    public function skir_form_requests_run_the_native_laravel_lifecycle_from_the_decoded_bag(): void
    {
        NativeLifecycleSkirFormRequest::reset();
        SimpleDataObjectFixture::reset();
        NativeSkirFormRequestController::$invocations = 0;
        $this->app->afterResolving(
            NativeLifecycleSkirFormRequest::class,
            static function (): void {
                NativeLifecycleSkirFormRequest::$containerResolutions++;
            },
        );

        $result = $this->invokeWithinMethodRequestScope(
            NativeSkirFormRequestController::class,
            [
                'id' => 42,
                'name' => '  Maxim  ',
                'metadata' => 'decoded',
            ],
        );

        $this->assertSame([
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'decoded',
        ], $result);
        $this->assertSame(1, NativeSkirFormRequestController::$invocations);
        $this->assertSame(1, NativeLifecycleSkirFormRequest::$containerResolutions);
        $this->assertSame(1, NativeLifecycleSkirFormRequest::$authorizations);
        $this->assertSame(1, NativeLifecycleSkirFormRequest::$ruleResolutions);
        $this->assertSame(1, NativeLifecycleSkirFormRequest::$afterResolutions);
        $this->assertSame(1, NativeLifecycleSkirFormRequest::$afterValidations);
        $this->assertSame([
            'id' => 42,
            'name' => '  Maxim  ',
            'metadata' => 'decoded',
        ], NativeLifecycleSkirFormRequest::$initialInput);
        $this->assertSame([
            'tenant' => 'acme',
            'userId' => 42,
            'trace' => 'trace-123',
            'session' => 'session-123',
        ], NativeLifecycleSkirFormRequest::$preparationContext);
        $this->assertSame([
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'decoded',
        ], NativeLifecycleSkirFormRequest::$validatedInput);
        $this->assertSame(1, SimpleDataObjectFixture::$factoryCalls);
        $this->assertSame('makeFromSkirPayload', SimpleDataObjectFixture::$factory);
    }

    #[Test]
    public function skir_uses_the_laravel_data_generator_contract_once_after_validation(): void
    {
        LaravelDataContractSkirRequest::reset();
        LaravelDataFixture::reset();

        $result = $this->invokeWithinMethodRequestScope(
            LaravelDataContractController::class,
            [
                'id' => 42,
                'name' => '  Laravel Data  ',
                'metadata' => 'decoded',
            ],
        );

        $this->assertSame('Laravel Data', $result);
        $this->assertSame(1, LaravelDataFixture::$factoryCalls);
        $this->assertSame('makeFromSkirPayload', LaravelDataFixture::$factory);
        $this->assertSame([
            'prepare',
            'authorize',
            'rules',
            'after',
            'passed',
            'factory',
        ], LaravelDataContractSkirRequest::$events);
    }

    #[Test]
    public function skir_uses_the_standard_php_generator_contract_once_after_validation(): void
    {
        StandardPhpContractSkirRequest::reset();
        StandardPhpFixture::reset();

        $result = $this->invokeWithinMethodRequestScope(
            StandardPhpContractController::class,
            [
                'id' => 42,
                'name' => '  Standard PHP  ',
                'metadata' => 'decoded',
            ],
        );

        $this->assertSame('Standard PHP', $result);
        $this->assertSame(1, StandardPhpFixture::$factoryCalls);
        $this->assertSame('fromArray', StandardPhpFixture::$factory);
        $this->assertSame([
            'prepare',
            'authorize',
            'rules',
            'after',
            'passed',
            'factory',
        ], StandardPhpContractSkirRequest::$events);
    }

    #[Test]
    public function it_hydrates_each_supported_generated_object_factory(): void
    {
        $hydrator = app(SkirPayloadHydrator::class);

        $fromPayload = $hydrator->hydrate(MakeFromSkirPayloadFixture::class, ['name' => 'payload']);
        $fromArray = $hydrator->hydrate(FromArrayFixture::class, ['name' => 'array']);
        $fromValue = $hydrator->hydrate(FromSkirValueFixture::class, 'value');

        $this->assertTrue($hydrator->supports(MakeFromSkirPayloadFixture::class));
        $this->assertTrue($hydrator->supports(FromArrayFixture::class));
        $this->assertTrue($hydrator->supports(FromSkirValueFixture::class));
        $this->assertSame('payload', $fromPayload->name);
        $this->assertSame('array', $fromArray->name);
        $this->assertSame('value', $fromValue->name);
    }

    #[Test]
    public function it_prefers_the_generated_payload_factory_methods_in_supported_order(): void
    {
        $hydrator = app(SkirPayloadHydrator::class);

        $payload = $hydrator->hydrate(MultipleFactoryFixture::class, ['name' => 'Maxim']);

        $this->assertSame('makeFromSkirPayload', $payload->factory);
    }

    #[Test]
    public function it_rejects_classes_without_a_generated_object_factory(): void
    {
        $hydrator = app(SkirPayloadHydrator::class);

        $this->assertFalse($hydrator->supports(UnsupportedPayloadFixture::class));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not define a supported Skir payload factory');

        $hydrator->hydrate(UnsupportedPayloadFixture::class, []);
    }

    #[Test]
    public function it_rejects_factory_methods_that_are_not_public_static_capabilities(): void
    {
        $hydrator = app(SkirPayloadHydrator::class);

        $this->assertFalse($hydrator->supports(InaccessibleFactoryFixture::class));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not define a supported Skir payload factory');

        $hydrator->hydrate(InaccessibleFactoryFixture::class, []);
    }

    #[Test]
    public function it_rejects_magic_static_factory_fallbacks_without_invoking_them(): void
    {
        $hydrator = app(SkirPayloadHydrator::class);
        MagicStaticFactoryFixture::$invocations = 0;

        $this->assertFalse($hydrator->supports(MagicStaticFactoryFixture::class));

        try {
            $hydrator->hydrate(MagicStaticFactoryFixture::class, []);

            $this->fail('A magic static fallback was accepted as a Skir payload factory.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'does not define a supported Skir payload factory',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, MagicStaticFactoryFixture::$invocations);
    }

    #[Test]
    public function it_validates_only_the_decoded_payload_and_hydrates_the_prepared_payload(): void
    {
        RenameUserFormController::$invocations = 0;

        $result = $this->invokeWithinMethodRequestScope(RenameUserFormController::class, [
            'id' => 42,
            'name' => '  Maxim  ',
            'metadata' => 'preserved',
        ]);

        $this->assertSame([
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'preserved',
        ], $result);
        $this->assertSame(1, RenameUserFormController::$invocations);
        $this->assertSame([
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'preserved',
        ], RenameUserFormController::$requestPayload);
    }

    #[Test]
    public function it_excludes_outer_query_parameters_from_the_decoded_form_request_payload(): void
    {
        RenameUserFormController::$invocations = 0;
        RenameUserFormController::$requestPayload = [];

        $result = $this->invokeWithinMethodRequestScope(RenameUserFormController::class, [
            'id' => 42,
            'name' => '  Maxim  ',
            'metadata' => 'decoded',
        ]);

        $this->assertSame([
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'decoded',
        ], $result);
        $this->assertSame([
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'decoded',
        ], RenameUserFormController::$requestPayload);
    }

    #[Test]
    public function it_preserves_the_original_user_route_and_headers_during_authorization(): void
    {
        ContextAwareSkirRequest::$authorizationContext = [];
        ContextFormController::$originalTransportPayload = [];

        $this->invokeWithinMethodRequestScope(ContextFormController::class, [
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'preserved',
        ]);

        $this->assertSame([
            'userId' => 42,
            'tenant' => 'acme',
            'trace' => 'trace-123',
        ], ContextAwareSkirRequest::$authorizationContext);
        $this->assertSame([
            'method' => 'RenameUser',
            'request' => ['name' => 'outer'],
        ], ContextFormController::$originalTransportPayload);
    }

    #[Test]
    public function it_stops_controller_execution_when_semantic_validation_fails(): void
    {
        RenameUserFormController::$invocations = 0;

        try {
            $this->invokeWithinMethodRequestScope(RenameUserFormController::class, [
                'id' => 42,
                'name' => '   ',
                'metadata' => 'preserved',
            ]);

            $this->fail('A validation exception was not translated.');
        } catch (SkirServerException $exception) {
            $this->assertSame('skir_validation_failed', $exception->errorCode);
            $this->assertSame([
                'name' => ['The name field is required.'],
            ], $exception->details);
        }

        $this->assertSame(0, RenameUserFormController::$invocations);
    }

    #[Test]
    public function it_stops_controller_execution_when_authorization_fails(): void
    {
        UnauthorizedFormController::$invocations = 0;

        try {
            $this->invokeWithinMethodRequestScope(UnauthorizedFormController::class, [
                'id' => 42,
                'name' => 'Maxim',
                'metadata' => 'preserved',
            ]);

            $this->fail('An authorization exception was not translated.');
        } catch (SkirServerException $exception) {
            $this->assertSame('skir_authorization_failed', $exception->errorCode);
        }

        $this->assertSame(0, UnauthorizedFormController::$invocations);
    }

    #[Test]
    public function it_preserves_validation_exceptions_thrown_by_controller_business_logic(): void
    {
        $this->expectException(ValidationException::class);

        $this->invokeWithinMethodRequestScope(BusinessValidationController::class, [
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'preserved',
        ]);
    }

    #[Test]
    public function it_preserves_not_found_authorization_exceptions_thrown_by_controller_business_logic(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->invokeWithinMethodRequestScope(BusinessAuthorizationController::class, [
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'preserved',
        ]);
    }

    #[Test]
    public function it_keeps_direct_generated_object_injection_available_without_a_form_request(): void
    {
        Route::skirRpc('/direct-payload-rpc', [
            Skir::method(FormRequestSkirMethod::DirectUser, DirectPayloadController::class),
        ], SkirCodecs::standardJson());

        $this
            ->postJson('/direct-payload-rpc', [
                'method' => 'DirectUser',
                'request' => [
                    'id' => 42,
                    'name' => 'Maxim',
                ],
            ])
            ->assertOk()
            ->assertExactJson([
                'id' => 42,
                'name' => 'Maxim directly injected',
            ]);
    }

    /** @param class-string $controller */
    private function invokeWithinMethodRequestScope(string $controller, array $decodedPayload): mixed
    {
        $route = Route::skirRpc('/native-form-request/{tenant}', [
            Skir::method(FormRequestSkirMethod::RenameUser, $controller),
        ], SkirCodecs::standardJson());
        $route->parameters = ['tenant' => 'acme'];

        $transportRequest = Request::create(
            '/native-form-request/acme?queryOnly=contamination',
            'POST',
            ['staleInput' => 'transport'],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SKIR_TRACE' => 'trace-123',
            ],
            '{"method":"RenameUser","request":{"name":"outer"}}',
        );
        $transportRequest->setJson(new InputBag([
            'method' => 'RenameUser',
            'request' => ['name' => 'outer'],
        ]));
        $transportRequest->setRouteResolver(static fn (): LaravelRoute => $route);
        $session = new Store('skir-form-request', new ArraySessionHandler(120));
        $session->put('skir_session', 'session-123');
        $transportRequest->setLaravelSession($session);
        $this->app->instance('request', $transportRequest);
        $transportRequest->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => 42]));

        $server = $route->defaults['skirServer'] ?? null;
        $this->assertInstanceOf(SkirServer::class, $server);
        $context = new RequestContext($transportRequest, FormRequestSkirMethod::RenameUser->descriptor());

        return app(SkirMethodRequestScope::class)->run(
            $transportRequest,
            $decodedPayload,
            static fn (): mixed => $server
                ->procedure('RenameUser')
                ->prepare($decodedPayload, $context)
                ->invoke(),
        );
    }
}

trait RecordsNativeFormRequestLifecycle
{
    /** @var array<string, mixed> */
    public static array $initialInput = [];

    /** @var array<string, mixed> */
    public static array $preparationContext = [];

    /** @var array<string, mixed> */
    public static array $validatedInput = [];

    public static int $containerResolutions = 0;

    public static int $authorizations = 0;

    public static int $ruleResolutions = 0;

    public static int $afterResolutions = 0;

    public static int $afterValidations = 0;

    public function authorize(): bool
    {
        self::$authorizations++;

        return $this->session()->get('skir_session') === 'session-123';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        self::$ruleResolutions++;

        return [
            'id' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:1'],
            'metadata' => ['required', 'string'],
            'method' => ['prohibited'],
            'request' => ['prohibited'],
            'queryOnly' => ['prohibited'],
            'staleInput' => ['prohibited'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        self::$afterResolutions++;

        return [
            static function (Validator $validator): void {
                self::$afterValidations++;

                if ($validator->getData()['name'] === 'blocked') {
                    $validator->errors()->add('name', 'The prepared name is blocked.');
                }
            },
        ];
    }

    public static function reset(): void
    {
        self::$initialInput = [];
        self::$preparationContext = [];
        self::$validatedInput = [];
        self::$containerResolutions = 0;
        self::$authorizations = 0;
        self::$ruleResolutions = 0;
        self::$afterResolutions = 0;
        self::$afterValidations = 0;
    }

    protected function prepareForValidation(): void
    {
        self::$initialInput = $this->all();
        self::$preparationContext = [
            'tenant' => $this->route('tenant'),
            'userId' => $this->user()?->getAuthIdentifier(),
            'trace' => $this->header('X-Skir-Trace'),
            'session' => $this->session()->get('skir_session'),
        ];
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    protected function passedValidation(): void
    {
        self::$validatedInput = $this->all();
    }
}

final class NativeLifecycleFormRequest extends FormRequest
{
    use RecordsNativeFormRequestLifecycle;
}

final class NativeLifecycleSkirFormRequest extends SkirFormRequest
{
    use RecordsNativeFormRequestLifecycle;

    /** @return class-string<SimpleDataObjectFixture> */
    protected function skirClass(): string
    {
        return SimpleDataObjectFixture::class;
    }
}

final class NativeFormRequestController
{
    public static int $invocations = 0;

    /** @return array<string, mixed> */
    public function __invoke(NativeLifecycleFormRequest $request): array
    {
        self::$invocations++;

        return $request->all();
    }
}

final class NativeSkirFormRequestController
{
    public static int $invocations = 0;

    /** @return array<string, mixed> */
    public function __invoke(NativeLifecycleSkirFormRequest $request): array
    {
        self::$invocations++;

        /** @var SimpleDataObjectFixture $payload */
        $payload = $request->skir();

        return $payload->toArray();
    }
}

final class SimpleDataObjectFixture
{
    public static int $factoryCalls = 0;

    public static string $factory = '';

    public function __construct(
        public int $id,
        public string $name,
        public string $metadata,
    ) {}

    /** @param array{id: int, name: string, metadata: string} $payload */
    public static function makeFromSkirPayload(array $payload): self
    {
        self::$factoryCalls++;
        self::$factory = __FUNCTION__;

        return new self($payload['id'], $payload['name'], $payload['metadata']);
    }

    public static function reset(): void
    {
        self::$factoryCalls = 0;
        self::$factory = '';
    }

    /** @return array{id: int, name: string, metadata: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'metadata' => $this->metadata,
        ];
    }
}

trait RecordsFactoryContractLifecycle
{
    /** @var list<string> */
    public static array $events = [];

    public function authorize(): bool
    {
        self::$events[] = 'authorize';

        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        self::$events[] = 'rules';

        return [
            'id' => ['required', 'integer'],
            'name' => ['required', 'string'],
            'metadata' => ['required', 'string'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            static function (): void {
                self::$events[] = 'after';
            },
        ];
    }

    public static function reset(): void
    {
        self::$events = [];
    }

    protected function prepareForValidation(): void
    {
        self::$events[] = 'prepare';
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    protected function passedValidation(): void
    {
        self::$events[] = 'passed';
    }
}

final class LaravelDataContractSkirRequest extends SkirFormRequest
{
    use RecordsFactoryContractLifecycle;

    /** @return class-string<LaravelDataFixture> */
    protected function skirClass(): string
    {
        return LaravelDataFixture::class;
    }
}

final class StandardPhpContractSkirRequest extends SkirFormRequest
{
    use RecordsFactoryContractLifecycle;

    /** @return class-string<StandardPhpFixture> */
    protected function skirClass(): string
    {
        return StandardPhpFixture::class;
    }
}

final class LaravelDataContractController
{
    public function __invoke(LaravelDataContractSkirRequest $request): string
    {
        /** @var LaravelDataFixture $payload */
        $payload = $request->skir();

        return $payload->name;
    }
}

final class StandardPhpContractController
{
    public function __invoke(StandardPhpContractSkirRequest $request): string
    {
        /** @var StandardPhpFixture $payload */
        $payload = $request->skir();

        return $payload->name;
    }
}

final class LaravelDataFixture
{
    public static int $factoryCalls = 0;

    public static string $factory = '';

    public function __construct(public string $name) {}

    /** @param array{name: string} $payload */
    public static function makeFromSkirPayload(array $payload): self
    {
        self::$factoryCalls++;
        self::$factory = __FUNCTION__;
        LaravelDataContractSkirRequest::$events[] = 'factory';

        return new self($payload['name']);
    }

    public static function reset(): void
    {
        self::$factoryCalls = 0;
        self::$factory = '';
    }
}

final class StandardPhpFixture
{
    public static int $factoryCalls = 0;

    public static string $factory = '';

    public function __construct(public string $name) {}

    /** @param array{name: string} $payload */
    public static function fromArray(array $payload): self
    {
        self::$factoryCalls++;
        self::$factory = __FUNCTION__;
        StandardPhpContractSkirRequest::$events[] = 'factory';

        return new self($payload['name']);
    }

    public static function reset(): void
    {
        self::$factoryCalls = 0;
        self::$factory = '';
    }
}

final readonly class MakeFromSkirPayloadFixture
{
    public function __construct(public string $name) {}

    /** @param array{name: string} $payload */
    public static function makeFromSkirPayload(array $payload): self
    {
        return new self($payload['name']);
    }
}

final readonly class FromArrayFixture
{
    public function __construct(public string $name) {}

    /** @param array{name: string} $payload */
    public static function fromArray(array $payload): self
    {
        return new self($payload['name']);
    }
}

final readonly class FromSkirValueFixture
{
    public function __construct(public string $name) {}

    public static function fromSkirValue(string $payload): self
    {
        return new self($payload);
    }
}

final readonly class MultipleFactoryFixture
{
    public function __construct(public string $factory) {}

    /** @param array{name: string} $payload */
    public static function makeFromSkirPayload(array $payload): self
    {
        return new self('makeFromSkirPayload');
    }

    /** @param array{name: string} $payload */
    public static function fromArray(array $payload): self
    {
        return new self('fromArray');
    }

    /** @param array{name: string} $payload */
    public static function fromSkirValue(array $payload): self
    {
        return new self('fromSkirValue');
    }
}

final readonly class UnsupportedPayloadFixture {}

final class InaccessibleFactoryFixture
{
    private static function makeFromSkirPayload(array $payload): self
    {
        return new self;
    }
}

final class MagicStaticFactoryFixture
{
    public static int $invocations = 0;

    public static function __callStatic(string $name, array $arguments): self
    {
        self::$invocations++;

        return new self;
    }
}

final class RenameUserSkirRequest extends SkirFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:1'],
            'method' => ['prohibited'],
            'request' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    /** @return class-string<PreparedUserPayload> */
    protected function skirClass(): string
    {
        return PreparedUserPayload::class;
    }
}

final class ContextAwareSkirRequest extends SkirFormRequest
{
    /** @var array{userId?: mixed, tenant?: mixed, trace?: mixed} */
    public static array $authorizationContext = [];

    public function authorize(): bool
    {
        self::$authorizationContext = [
            'userId' => $this->user()?->getAuthIdentifier(),
            'tenant' => $this->route('tenant'),
            'trace' => $this->header('X-Skir-Trace'),
        ];

        return self::$authorizationContext === [
            'userId' => 42,
            'tenant' => 'acme',
            'trace' => 'trace-123',
        ];
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer'],
            'name' => ['required', 'string'],
            'metadata' => ['required', 'string'],
        ];
    }

    /** @return class-string<PreparedUserPayload> */
    protected function skirClass(): string
    {
        return PreparedUserPayload::class;
    }
}

final class UnauthorizedSkirRequest extends SkirFormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer'],
            'name' => ['required', 'string'],
            'metadata' => ['required', 'string'],
        ];
    }

    /** @return class-string<PreparedUserPayload> */
    protected function skirClass(): string
    {
        return PreparedUserPayload::class;
    }
}

final readonly class PreparedUserPayload
{
    public function __construct(
        public int $id,
        public string $name,
        public string $metadata = '',
    ) {}

    /**
     * @param array{
     *     id: int,
     *     name: string,
     *     metadata?: string
     * } $payload
     */
    public static function makeFromSkirPayload(array $payload): self
    {
        return new self($payload['id'], $payload['name'], $payload['metadata'] ?? '');
    }

    /** @return array{id: int, name: string, metadata: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'metadata' => $this->metadata,
        ];
    }
}

final class RenameUserFormController
{
    public static int $invocations = 0;

    /** @var array<string, mixed> */
    public static array $requestPayload = [];

    public function __invoke(RenameUserSkirRequest $request): PreparedUserPayload
    {
        self::$invocations++;
        self::$requestPayload = $request->all();

        /** @var PreparedUserPayload $payload */
        $payload = $request->skir();

        return $payload;
    }
}

final class DirectPayloadController
{
    public function __invoke(FromArrayUserPayload $payload): FromArrayUserPayload
    {
        return new FromArrayUserPayload($payload->id, "{$payload->name} directly injected");
    }
}

final class ContextFormController
{
    /** @var array<string, mixed> */
    public static array $originalTransportPayload = [];

    public function __invoke(ContextAwareSkirRequest $request, SkirContext $context): PreparedUserPayload
    {
        self::$originalTransportPayload = $context->request->json()->all();

        /** @var PreparedUserPayload $payload */
        $payload = $request->skir();

        return $payload;
    }
}

final class UnauthorizedFormController
{
    public static int $invocations = 0;

    public function __invoke(UnauthorizedSkirRequest $request): PreparedUserPayload
    {
        self::$invocations++;

        /** @var PreparedUserPayload $payload */
        $payload = $request->skir();

        return $payload;
    }
}

final class BusinessValidationController
{
    public function __invoke(RenameUserSkirRequest $request): PreparedUserPayload
    {
        throw ValidationException::withMessages([
            'business' => ['The business field is invalid.'],
        ])->status(409);
    }
}

final class BusinessAuthorizationController
{
    public function __invoke(RenameUserSkirRequest $request): PreparedUserPayload
    {
        throw (new AuthorizationException)->asNotFound();
    }
}

final readonly class FromArrayUserPayload
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    /** @param array{id: int, name: string} $payload */
    public static function fromArray(array $payload): self
    {
        return new self($payload['id'], $payload['name']);
    }

    /** @return array{id: int, name: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

enum FormRequestSkirMethod implements SkirMethodReference
{
    case RenameUser;
    case DirectUser;

    public function descriptor(): MethodDescriptor
    {
        $renameUserType = Type::struct([
            Field::value('id', 0, Type::int32()),
            Field::value('name', 1, Type::string()),
            Field::value('metadata', 2, Type::string()),
        ]);

        $directUserType = Type::struct([
            Field::value('id', 0, Type::int32()),
            Field::value('name', 1, Type::string()),
        ]);

        return match ($this) {
            self::RenameUser => new MethodDescriptor('RenameUser', 3001, $renameUserType, $renameUserType),
            self::DirectUser => new MethodDescriptor('DirectUser', 3002, $directUserType, $directUserType),
        };
    }
}
