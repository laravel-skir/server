<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Support\Facades\Route;
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
            ->assertUnprocessable();

        $this->assertSame(0, RenameUserFormController::$invocations);
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
