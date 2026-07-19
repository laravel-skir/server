<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Skir\Runtime\Field;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\Contracts\SkirMethodReference;
use Skir\Server\Facades\Skir;
use Skir\Server\RequestContext;
use Skir\Server\SkirContext;
use Skir\Server\Tests\TestCase;

final class ControllerProcedureInvokerTest extends TestCase
{
    #[Test]
    public function it_prefers_make_from_skir_payload_for_request_hydration(): void
    {
        $this->registerController('/request-hydration');

        $this
            ->postJson('/request-hydration', [
                'method' => 'PreferRequestHydrator',
                'request' => ['value'],
            ])
            ->assertOk()
            ->assertContent('"make:value"');
    }

    #[Test]
    public function it_prefers_to_skir_array_for_response_normalization(): void
    {
        $this->registerController('/response-normalization');

        $this
            ->postJson('/response-normalization', [
                'method' => 'PreferResponseNormalizer',
                'request' => 'value',
            ])
            ->assertOk()
            ->assertContent('"skir:value"');
    }

    #[Test]
    public function it_injects_request_context_into_attributed_controllers(): void
    {
        $this->registerController('/request-context');

        $this
            ->postJson('/request-context', [
                'method' => 'RequestContext',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertContent(json_encode(RequestContext::class, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_resolves_controller_constructor_dependencies_from_the_container(): void
    {
        $this->registerController('/controller-dependency');

        $this
            ->postJson('/controller-dependency', [
                'method' => 'Dependency',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertContent('"resolved:5"');
    }

    #[Test]
    public function it_ignores_static_attributed_controller_methods(): void
    {
        $this->registerController('/static-method');

        $this
            ->postJson('/static-method', [
                'method' => 'StaticMethod',
                'request' => 5.0,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'skir_method_not_found');
    }

    #[Test]
    public function it_passes_decoded_requests_to_untyped_parameters(): void
    {
        $this->registerController('/untyped-parameter');

        $this
            ->postJson('/untyped-parameter', [
                'method' => 'UntypedParameter',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertContent('10');
    }

    #[Test]
    public function it_supports_controller_methods_that_only_request_context(): void
    {
        $this->registerController('/context-only');

        $this
            ->postJson('/context-only', [
                'method' => 'ContextOnly',
                'request' => 5.0,
            ])
            ->assertOk()
            ->assertContent('"ContextOnly"');
    }

    #[Test]
    public function unexpected_controller_exceptions_are_left_to_laravel(): void
    {
        $this->registerController('/controller-failure');
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Controller failed.');

        $this->postJson('/controller-failure', [
            'method' => 'ControllerFailure',
            'request' => 5.0,
        ]);
    }

    private function registerController(string $uri): void
    {
        Route::skirRpc($uri, [
            Skir::controller(InvocationController::class),
        ]);
    }
}

enum InvocationSkirMethod implements SkirMethodReference
{
    case PreferRequestHydrator;
    case PreferResponseNormalizer;
    case RequestContext;
    case Dependency;
    case StaticMethod;
    case UntypedParameter;
    case ContextOnly;
    case ControllerFailure;

    public function descriptor(): MethodDescriptor
    {
        return match ($this) {
            self::PreferRequestHydrator => new MethodDescriptor(
                'PreferRequestHydrator',
                4001,
                Type::struct([Field::value('value', 0, Type::string())]),
                Type::string(),
            ),
            self::PreferResponseNormalizer => new MethodDescriptor(
                'PreferResponseNormalizer',
                4002,
                Type::string(),
                Type::string(),
            ),
            self::RequestContext => new MethodDescriptor('RequestContext', 4003, Type::float32(), Type::string()),
            self::Dependency => new MethodDescriptor('Dependency', 4004, Type::float32(), Type::string()),
            self::StaticMethod => new MethodDescriptor('StaticMethod', 4005, Type::float32(), Type::float32()),
            self::UntypedParameter => new MethodDescriptor('UntypedParameter', 4006, Type::float32(), Type::float32()),
            self::ContextOnly => new MethodDescriptor('ContextOnly', 4007, Type::float32(), Type::string()),
            self::ControllerFailure => new MethodDescriptor('ControllerFailure', 4008, Type::float32(), Type::float32()),
        };
    }
}

final class InvocationController
{
    public function __construct(
        private readonly InvocationDependency $dependency,
    ) {}

    #[SkirMethod(InvocationSkirMethod::PreferRequestHydrator)]
    public function preferRequestHydrator(PreferredRequestPayload $request): string
    {
        return $request->value;
    }

    #[SkirMethod(InvocationSkirMethod::PreferResponseNormalizer)]
    public function preferResponseNormalizer(string $request): PreferredResponsePayload
    {
        return new PreferredResponsePayload($request);
    }

    #[SkirMethod(InvocationSkirMethod::RequestContext)]
    public function requestContext(float $request, RequestContext $context): string
    {
        return $context::class;
    }

    #[SkirMethod(InvocationSkirMethod::Dependency)]
    public function dependency(float $request): string
    {
        return $this->dependency->describe($request);
    }

    #[SkirMethod(InvocationSkirMethod::StaticMethod)]
    public static function staticMethod(float $request): float
    {
        return $request;
    }

    #[SkirMethod(InvocationSkirMethod::UntypedParameter)]
    public function untypedParameter($request): float
    {
        return $request * 2;
    }

    #[SkirMethod(InvocationSkirMethod::ContextOnly)]
    public function contextOnly(SkirContext $context): string
    {
        return $context->method->name;
    }

    #[SkirMethod(InvocationSkirMethod::ControllerFailure)]
    public function controllerFailure(float $request): float
    {
        throw new RuntimeException('Controller failed.');
    }
}

final class InvocationDependency
{
    public function describe(float $value): string
    {
        return "resolved:{$value}";
    }
}

final readonly class PreferredRequestPayload
{
    public function __construct(
        public string $value,
    ) {}

    /**
     * @param  array{value: string}  $payload
     */
    public static function makeFromSkirPayload(array $payload): self
    {
        return new self("make:{$payload['value']}");
    }

    /**
     * @param  array{value: string}  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self("array:{$payload['value']}");
    }
}

final readonly class PreferredResponsePayload
{
    public function __construct(
        private string $value,
    ) {}

    public function toSkirArray(): string
    {
        return "skir:{$this->value}";
    }

    public function toArray(): string
    {
        return "array:{$this->value}";
    }
}
