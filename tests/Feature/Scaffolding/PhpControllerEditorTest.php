<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Scaffolding;

use PHPUnit\Framework\Attributes\Test;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Skir\Server\Scaffolding\PhpControllerEditor;
use Skir\Server\Tests\TestCase;

final class PhpControllerEditorTest extends TestCase
{
    #[Test]
    public function it_adds_only_missing_methods_and_preserves_existing_method_source(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Skir\Admin;

use App\Skir\Methods\AdminMethod as Methods;
use Skir\Server\Attributes\SkirMethod;

final class AdminController
{
    #[SkirMethod(Methods::Existing)]
    public function carefullyWritten(): string
    {
        return 'handwritten'; // preserve this exactly
    }
}
PHP;

        $result = app(PhpControllerEditor::class)->edit(
            $source,
            '/app/Skir/Admin/AdminController.php',
            'App\Skir\Admin',
            'AdminController',
            [$this->method()],
            [],
        );

        self::assertTrue($result->changed);
        self::assertStringContainsString("return 'handwritten'; // preserve this exactly", $result->source);
        self::assertStringContainsString('#[SkirMethod(Methods::GetUser)]', $result->source);
        self::assertStringContainsString('public function getUser(', $result->source);
        self::assertSame([
            'Skir controller [/app/Skir/Admin/AdminController.php] contains stale Skir method [App\\Skir\\Methods\\AdminMethod::Existing]; it was not removed.',
        ], $result->warnings);
    }

    #[Test]
    public function an_existing_identity_is_a_no_op_even_when_its_php_method_name_differs(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Skir\Admin;

use App\Skir\Methods\AdminMethod as Methods;
use Skir\Server\Attributes\SkirMethod;

final class AdminController
{
    #[SkirMethod(Methods::GetUser)]
    public function applicationChosenName(): string
    {
        return 'done';
    }
}
PHP;

        $result = app(PhpControllerEditor::class)->edit(
            $source,
            '/app/Skir/Admin/AdminController.php',
            'App\Skir\Admin',
            'AdminController',
            [$this->method()],
            [],
        );

        self::assertFalse($result->changed);
        self::assertSame($source, $result->source);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function it_rejects_an_occupied_php_method_name_without_the_expected_identity(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Skir\Admin;

final class AdminController
{
    public function getUser(): string
    {
        return 'application method';
    }
}
PHP;

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('PHP method [getUser] already exists without Skir identity [App\Skir\Methods\AdminMethod::GetUser]');

        app(PhpControllerEditor::class)->edit(
            $source,
            '/app/Skir/Admin/AdminController.php',
            'App\Skir\Admin',
            'AdminController',
            [$this->method()],
            [],
        );
    }

    #[Test]
    public function it_rejects_an_occupied_php_method_with_a_different_skir_identity(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Skir\Admin;

use App\Skir\Methods\AdminMethod;
use Skir\Server\Attributes\SkirMethod;

final class AdminController
{
    #[SkirMethod(AdminMethod::Different)]
    public function getUser(): string
    {
        return 'application method';
    }
}
PHP;

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('PHP method [getUser] already exists without Skir identity [App\Skir\Methods\AdminMethod::GetUser]');

        app(PhpControllerEditor::class)->edit(
            $source,
            '/app/Skir/Admin/AdminController.php',
            'App\Skir\Admin',
            'AdminController',
            [$this->method()],
            [],
        );
    }

    #[Test]
    public function it_respects_existing_aliases_and_avoids_import_name_collisions(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Skir\Admin;

use Domain\Other\AdminMethod;
use Skir\Server\Attributes\SkirMethod as RpcMethod;

final class AdminController
{
}
PHP;

        $result = app(PhpControllerEditor::class)->edit(
            $source,
            '/app/Skir/Admin/AdminController.php',
            'App\Skir\Admin',
            'AdminController',
            [$this->method()],
            [],
        );

        self::assertStringContainsString('use App\Skir\Methods\AdminMethod as MethodsAdminMethod;', $result->source);
        self::assertStringNotContainsString('use Skir\Server\Attributes\SkirMethod;', $result->source);
        self::assertStringContainsString('#[RpcMethod(MethodsAdminMethod::GetUser)]', $result->source);
    }

    #[Test]
    public function it_does_not_shadow_existing_unimported_class_references(): void
    {
        $source = <<<'PHP'
<?php

namespace App\Skir\Admin;

final class AdminController
{
    public function applicationMethod(Payload $payload): Payload
    {
        return $payload;
    }
}
PHP;
        $method = new SkirMethodDefinition(
            module: 'Admin',
            name: 'GetPayload',
            enumClass: 'App\Skir\Methods\AdminMethod',
            enumCase: 'GetPayload',
            phpMethod: 'getPayload',
            requestType: 'Payload',
            requestClass: 'Vendor\Transport\Payload',
            responseType: 'Payload',
            responseClass: 'Vendor\Transport\Payload',
        );

        $result = app(PhpControllerEditor::class)->edit(
            $source,
            '/app/Skir/Admin/AdminController.php',
            'App\Skir\Admin',
            'AdminController',
            [$method],
            [],
        );

        self::assertStringContainsString('use Vendor\Transport\Payload as TransportPayload;', $result->source);
        self::assertStringContainsString('applicationMethod(Payload $payload): Payload', $result->source);
        self::assertStringContainsString('getPayload(TransportPayload $request', $result->source);
    }

    #[Test]
    public function invalid_existing_php_is_rejected(): void
    {
        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('Existing Skir controller [/app/Skir/Admin/AdminController.php] is invalid PHP');

        app(PhpControllerEditor::class)->edit(
            '<?php final class Broken {',
            '/app/Skir/Admin/AdminController.php',
            'App\Skir\Admin',
            'AdminController',
            [$this->method()],
            [],
        );
    }

    private function method(): SkirMethodDefinition
    {
        return new SkirMethodDefinition(
            module: 'Admin',
            name: 'GetUser',
            enumClass: 'App\Skir\Methods\AdminMethod',
            enumCase: 'GetUser',
            phpMethod: 'getUser',
            requestType: 'GetUserRequest',
            requestClass: 'App\Skir\Dto\GetUserRequest',
            responseType: 'User',
            responseClass: 'App\Skir\Dto\User',
        );
    }
}
