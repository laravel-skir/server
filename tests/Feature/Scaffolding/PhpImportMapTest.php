<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Scaffolding;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skir\Server\Scaffolding\PhpImportMap;

final class PhpImportMapTest extends TestCase
{
    #[Test]
    public function alias_generation_terminates_after_namespace_and_fallback_aliases_are_exhausted(): void
    {
        $imports = new PhpImportMap('Controller');
        $imports->reserveAlias('Payload');
        $imports->reserveAlias('FooPayload');
        $imports->reserveAlias('AppFooPayload');
        $imports->reserveAlias('ImportedPayload');
        $imports->reserveAlias('ImportedPayload2');

        self::assertSame('ImportedPayload3', $imports->add('App\Foo\Payload'));
    }
}
