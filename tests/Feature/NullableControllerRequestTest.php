<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Codecs\SkirCodecs;
use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\Facades\Skir;
use Skir\Server\Http\Requests\SkirFormRequest;
use Skir\Server\SkirContext;
use Skir\Server\Tests\TestCase;
use TypeError;

final class NullableControllerRequestTest extends TestCase
{
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
    public function nullable_form_requests_receive_null_without_resolution(): void
    {
        $this->registerRoute(NullableRequestMethod::FormRequest, NullableFormRequestController::class);

        NullableGeneratedFormRequest::$authorizations = 0;
        NullableGeneratedFormRequest::$instances = 0;
        NullableGeneratedFormRequest::$ruleResolutions = 0;

        $this->postNullRequest('/nullable-form-request', 'FormRequest')
            ->assertOk()
            ->assertExactJson(['received' => null]);

        $this->assertSame(0, NullableGeneratedFormRequest::$authorizations);
        $this->assertSame(0, NullableGeneratedFormRequest::$instances);
        $this->assertSame(0, NullableGeneratedFormRequest::$ruleResolutions);
    }

    #[Test]
    public function non_null_nullable_requests_still_hydrate_and_validate(): void
    {
        $this->registerRoute(NullableRequestMethod::Standard, NullableStandardController::class);
        $this->registerRoute(NullableRequestMethod::Data, NullableDataController::class);
        $this->registerRoute(NullableRequestMethod::EnumValue, NullableEnumController::class);
        $this->registerRoute(NullableRequestMethod::FormRequest, NullableFormRequestController::class);

        NullableStandardRequest::$hydrations = 0;
        NullableDataRequest::$hydrations = 0;
        NullableRequestStatusHydrations::$count = 0;
        NullableGeneratedFormRequest::$authorizations = 0;
        NullableGeneratedFormRequest::$instances = 0;
        NullableGeneratedFormRequest::$ruleResolutions = 0;

        $this->postJson('/nullable-standard', ['method' => 'Standard', 'request' => ['name' => 'standard']])
            ->assertOk()
            ->assertExactJson(['received' => 'standard']);
        $this->postJson('/nullable-data', ['method' => 'Data', 'request' => ['name' => 'data']])
            ->assertOk()
            ->assertExactJson(['received' => 'data']);
        $this->postJson('/nullable-enum', ['method' => 'EnumValue', 'request' => 'active'])
            ->assertOk()
            ->assertExactJson(['received' => 'active']);
        $this->postJson('/nullable-form-request', ['method' => 'FormRequest', 'request' => ['name' => 'form']])
            ->assertOk()
            ->assertExactJson(['received' => 'form']);

        $this->assertSame(1, NullableStandardRequest::$hydrations);
        $this->assertSame(1, NullableDataRequest::$hydrations);
        $this->assertSame(1, NullableRequestStatusHydrations::$count);
        $this->assertSame(1, NullableGeneratedFormRequest::$authorizations);
        $this->assertSame(1, NullableGeneratedFormRequest::$instances);
        $this->assertSame(1, NullableGeneratedFormRequest::$ruleResolutions);
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
        Route::skirRpc($this->routePath($method), [
            Skir::method($method, $controller),
        ], SkirCodecs::standardJson());
    }

    private function postNullRequest(string $uri, string $method): TestResponse
    {
        return $this->postJson($uri, [
            'method' => $method,
            'request' => null,
        ]);
    }

    private function routePath(NullableRequestMethod $method): string
    {
        return match ($method) {
            NullableRequestMethod::Standard => '/nullable-standard',
            NullableRequestMethod::Data => '/nullable-data',
            NullableRequestMethod::EnumValue => '/nullable-enum',
            NullableRequestMethod::FormRequest => '/nullable-form-request',
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
    public static int $authorizations = 0;

    public static int $instances = 0;

    public static int $ruleResolutions = 0;

    public function __construct()
    {
        self::$instances++;

        parent::__construct();
    }

    public function authorize(): bool
    {
        self::$authorizations++;

        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        self::$ruleResolutions++;

        return ['name' => ['required', 'string']];
    }

    /** @return class-string<NullableStandardRequest> */
    public function skirClass(): string
    {
        return NullableStandardRequest::class;
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
        return ['received' => $request?->string('name')->toString()];
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
