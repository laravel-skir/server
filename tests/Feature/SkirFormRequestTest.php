<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\Field;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Codecs\SkirCodecs;
use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\Facades\Skir;
use Skir\Server\Http\Requests\SkirFormRequest;
use Skir\Server\Hydration\SkirPayloadHydrator;
use Skir\Server\SkirContext;
use Skir\Server\Tests\TestCase;

final class SkirFormRequestTest extends TestCase
{
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
    public function it_validates_only_the_decoded_payload_and_hydrates_the_prepared_payload(): void
    {
        Route::skirRpc('/form-request-rpc', [
            Skir::method(FormRequestSkirMethod::RenameUser, RenameUserFormController::class),
        ], SkirCodecs::standardJson());

        RenameUserFormController::$invocations = 0;

        $this
            ->postJson('/form-request-rpc', [
                'method' => 'RenameUser',
                'request' => [
                    'id' => 42,
                    'name' => '  Maxim  ',
                    'metadata' => 'preserved',
                ],
            ])
            ->assertOk()
            ->assertExactJson([
                'id' => 42,
                'name' => 'Maxim',
                'metadata' => 'preserved',
            ]);

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
        Route::skirRpc('/isolated-form-request-rpc', [
            Skir::method(FormRequestSkirMethod::RenameUser, RenameUserFormController::class),
        ], SkirCodecs::standardJson());

        RenameUserFormController::$invocations = 0;
        RenameUserFormController::$requestPayload = [];

        $this
            ->postJson('/isolated-form-request-rpc?method=OuterMethod&request[unexpected]=value&queryOnly=contamination', [
                'method' => 'RenameUser',
                'request' => [
                    'id' => 42,
                    'name' => '  Maxim  ',
                    'metadata' => 'decoded',
                ],
            ])
            ->assertOk()
            ->assertExactJson([
                'id' => 42,
                'name' => 'Maxim',
                'metadata' => 'decoded',
            ]);

        $this->assertSame([
            'id' => 42,
            'name' => 'Maxim',
            'metadata' => 'decoded',
        ], RenameUserFormController::$requestPayload);
    }

    #[Test]
    public function it_preserves_the_original_user_route_and_headers_during_authorization(): void
    {
        Route::skirRpc('/context-form-request-rpc/{tenant}', [
            Skir::method(FormRequestSkirMethod::RenameUser, ContextFormController::class),
        ], SkirCodecs::standardJson());

        ContextAwareSkirRequest::$authorizationContext = [];
        ContextFormController::$originalTransportPayload = [];
        $this->be(new GenericUser(['id' => 42]));

        $this
            ->postJson('/context-form-request-rpc/acme', [
                'method' => 'RenameUser',
                'request' => [
                    'id' => 42,
                    'name' => 'Maxim',
                    'metadata' => 'preserved',
                ],
            ], [
                'X-Skir-Trace' => 'trace-123',
            ])
            ->assertOk();

        $this->assertSame([
            'userId' => 42,
            'tenant' => 'acme',
            'trace' => 'trace-123',
        ], ContextAwareSkirRequest::$authorizationContext);
        $this->assertSame([
            'method' => 'RenameUser',
            'request' => [
                'id' => 42,
                'name' => 'Maxim',
                'metadata' => 'preserved',
            ],
        ], ContextFormController::$originalTransportPayload);
    }

    #[Test]
    public function it_stops_controller_execution_when_semantic_validation_fails(): void
    {
        Route::skirRpc('/invalid-form-request-rpc', [
            Skir::method(FormRequestSkirMethod::RenameUser, RenameUserFormController::class),
        ], SkirCodecs::standardJson());

        RenameUserFormController::$invocations = 0;

        $this
            ->postJson('/invalid-form-request-rpc', [
                'method' => 'RenameUser',
                'request' => [
                    'id' => 42,
                    'name' => '   ',
                    'metadata' => 'preserved',
                ],
            ])
            ->assertUnprocessable()
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_validation_failed',
                    'message' => 'The given data was invalid.',
                    'details' => [
                        'name' => [
                            'The name field is required.',
                        ],
                    ],
                ],
            ]);

        $this->assertSame(0, RenameUserFormController::$invocations);
    }

    #[Test]
    public function it_stops_controller_execution_when_authorization_fails(): void
    {
        Route::skirRpc('/unauthorized-form-request-rpc', [
            Skir::method(FormRequestSkirMethod::RenameUser, UnauthorizedFormController::class),
        ], SkirCodecs::standardJson());

        UnauthorizedFormController::$invocations = 0;

        $this
            ->postJson('/unauthorized-form-request-rpc', [
                'method' => 'RenameUser',
                'request' => [
                    'id' => 42,
                    'name' => 'Maxim',
                    'metadata' => 'preserved',
                ],
            ])
            ->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'skir_authorization_failed',
                    'message' => 'This action is unauthorized.',
                ],
            ]);

        $this->assertSame(0, UnauthorizedFormController::$invocations);
    }

    #[Test]
    public function it_preserves_validation_exceptions_thrown_by_controller_business_logic(): void
    {
        Route::skirRpc('/business-validation-rpc', [
            Skir::method(FormRequestSkirMethod::RenameUser, BusinessValidationController::class),
        ], SkirCodecs::standardJson());

        $this
            ->postJson('/business-validation-rpc', [
                'method' => 'RenameUser',
                'request' => [
                    'id' => 42,
                    'name' => 'Maxim',
                    'metadata' => 'preserved',
                ],
            ])
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'The business field is invalid.',
                'errors' => [
                    'business' => [
                        'The business field is invalid.',
                    ],
                ],
            ]);
    }

    #[Test]
    public function it_preserves_not_found_authorization_exceptions_thrown_by_controller_business_logic(): void
    {
        Route::skirRpc('/business-authorization-rpc', [
            Skir::method(FormRequestSkirMethod::RenameUser, BusinessAuthorizationController::class),
        ], SkirCodecs::standardJson());

        $this
            ->postJson('/business-authorization-rpc', [
                'method' => 'RenameUser',
                'request' => [
                    'id' => 42,
                    'name' => 'Maxim',
                    'metadata' => 'preserved',
                ],
            ])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Not Found',
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
