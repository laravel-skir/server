<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Server\Codecs\DenseJsonCodec;
use Skir\Server\Exceptions\SkirServerException;
use Skir\Server\ProcedureRegistry;
use Skir\Server\SkirContext;
use Skir\Server\SkirServer;

final class ProcedureRegistryTest extends TestCase
{
    #[Test]
    public function it_stores_and_lists_registered_procedures(): void
    {
        $registry = new ProcedureRegistry;
        $descriptor = $this->descriptor('Square');

        $registry->add($descriptor, fn (float $value): float => $value * $value);

        $this->assertSame($descriptor, $registry->get('Square')->descriptor);
        $this->assertSame(['Square'], array_keys($registry->all()));
    }

    #[Test]
    public function it_rejects_duplicate_procedure_names(): void
    {
        $registry = new ProcedureRegistry;
        $registry->add($this->descriptor('Square'), fn (float $value): float => $value);

        try {
            $registry->add($this->descriptor('Square'), fn (float $value): float => $value);
            $this->fail('Expected duplicate registration to fail.');
        } catch (SkirServerException $exception) {
            $this->assertSame('skir_duplicate_method', $exception->errorCode);
            $this->assertSame(422, $exception->status);
            $this->assertSame('Skir method [Square] is already registered on this endpoint.', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_unknown_procedure_names(): void
    {
        $registry = new ProcedureRegistry;

        try {
            $registry->get('Missing');
            $this->fail('Expected an unknown procedure lookup to fail.');
        } catch (SkirServerException $exception) {
            $this->assertSame('skir_method_not_found', $exception->errorCode);
            $this->assertSame(404, $exception->status);
            $this->assertSame('Skir method [Missing] is not registered.', $exception->getMessage());
        }
    }

    #[Test]
    public function registered_procedures_receive_the_request_and_context(): void
    {
        $registry = new ProcedureRegistry;
        $descriptor = $this->descriptor('Describe');
        $registry->add(
            $descriptor,
            fn (float $value, SkirContext $context): string => "{$context->method->name}:{$value}",
        );
        $context = new SkirContext(Request::create('/skir'), $descriptor);

        $result = $registry->get('Describe')->invoke(5.0, $context);

        $this->assertSame('Describe:5', $result);
    }

    #[Test]
    public function the_server_uses_dense_json_by_default(): void
    {
        $server = new SkirServer(new ProcedureRegistry);

        $this->assertInstanceOf(DenseJsonCodec::class, $server->codec());
        $this->assertSame([], $server->procedures());
    }

    private function descriptor(string $name): MethodDescriptor
    {
        return new MethodDescriptor($name, 6001, Type::float32(), Type::float32());
    }
}
