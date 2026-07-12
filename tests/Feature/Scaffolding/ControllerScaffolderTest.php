<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Scaffolding;

use PHPUnit\Framework\Attributes\Test;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\AtomicFilePublisher;
use Skir\Server\Scaffolding\ControllerScaffolder;
use Skir\Server\Scaffolding\ControllerStyle;
use Skir\Server\Scaffolding\FormRequestScaffolder;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Skir\Server\Scaffolding\ScaffoldingFilesystem;
use Skir\Server\Scaffolding\ScaffoldingSelection;
use Skir\Server\Tests\TestCase;
use Symfony\Component\Process\Process;

final class ControllerScaffolderTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/skir-controller-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory.'/app', 0777, true);
        app()->useAppPath($this->temporaryDirectory.'/app');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    #[Test]
    public function controller_styles_have_stable_configuration_values(): void
    {
        self::assertSame('module', ControllerStyle::Module->value);
        self::assertSame('invokable', ControllerStyle::Invokable->value);
        self::assertSame('single', ControllerStyle::Single->value);
    }

    #[Test]
    public function a_selection_requires_methods_and_only_accepts_a_single_controller_for_single_style(): void
    {
        try {
            new ScaffoldingSelection([], ControllerStyle::Module, null, false);
            self::fail('An empty selection should fail.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('at least one Skir method', $exception->getMessage());
        }

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('only be supplied for the [single] controller style');

        new ScaffoldingSelection(
            [$this->method()],
            ControllerStyle::Module,
            'App\\Skir\\EverythingController',
            false,
        );
    }

    #[Test]
    public function a_selection_requires_a_list_of_unique_method_ids(): void
    {
        try {
            new ScaffoldingSelection([1 => $this->method()], ControllerStyle::Module, null, false);
            self::fail('A keyed method array should fail.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('list of Skir method definitions', $exception->getMessage());
        }

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('selected more than once');

        new ScaffoldingSelection(
            [$this->method(), $this->method()],
            ControllerStyle::Invokable,
            null,
            false,
        );
    }

    #[Test]
    public function it_creates_one_module_controller_with_all_selected_module_methods(): void
    {
        $selection = new ScaffoldingSelection([
            $this->method(),
            $this->method(
                name: 'DeleteUser',
                enumCase: 'DeleteUser',
                phpMethod: 'deleteUser',
                requestType: 'int',
                requestClass: null,
                responseType: 'bool',
                responseClass: null,
            ),
        ], ControllerStyle::Module, null, false);

        $result = app(ControllerScaffolder::class)->scaffold($selection);

        $path = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        self::assertSame([$path], $result->createdPaths);
        self::assertSame([], $result->unchangedPaths);
        self::assertSame(['Skir::controller(UsersController::class)'], $result->routeHints);
        self::assertFileExists($path);

        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('declare(strict_types=1);', $source);
        self::assertStringContainsString('final class UsersController', $source);
        self::assertStringContainsString('#[SkirMethod(AdminUsersMethod::GetUser)]', $source);
        self::assertStringContainsString('public function getUser(GetUserRequest $request, SkirContext $context): User', $source);
        self::assertStringContainsString('#[SkirMethod(AdminUsersMethod::DeleteUser)]', $source);
        self::assertStringContainsString('public function deleteUser(int $request, SkirContext $context): bool', $source);
        self::assertSame(2, substr_count($source, 'throw new LogicException('));
        self::assertStringContainsString('Skir method [Admin.Users.GetUser] is not implemented.', $source);
        self::assertStringContainsString('Skir method [Admin.Users.DeleteUser] is not implemented.', $source);
    }

    #[Test]
    public function it_creates_one_invokable_controller_and_route_hint_per_method(): void
    {
        $selection = new ScaffoldingSelection([
            $this->method(),
            $this->method(name: 'DeleteUser', enumCase: 'DeleteUser', phpMethod: 'deleteUser'),
        ], ControllerStyle::Invokable, null, false);

        $result = app(ControllerScaffolder::class)->scaffold($selection);

        $getPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/GetUserController.php';
        $deletePath = $this->temporaryDirectory.'/app/Skir/Admin/Users/DeleteUserController.php';
        self::assertSame([$getPath, $deletePath], $result->createdPaths);
        self::assertSame([
            'Skir::method(AdminUsersMethod::GetUser, GetUserController::class)',
            'Skir::method(AdminUsersMethod::DeleteUser, DeleteUserController::class)',
        ], $result->routeHints);
        self::assertStringContainsString(
            'public function __invoke(GetUserRequest $request, SkirContext $context): User',
            (string) file_get_contents($getPath),
        );
    }

    #[Test]
    public function it_creates_a_default_or_named_single_controller_across_modules(): void
    {
        $selection = new ScaffoldingSelection([
            $this->method(),
            $this->method(
                module: 'Billing.Invoices',
                name: 'GetInvoice',
                enumClass: 'App\\Skir\\Methods\\BillingMethod',
                enumCase: 'GetInvoice',
                phpMethod: 'getInvoice',
                requestType: 'string',
                requestClass: null,
                responseType: '?int',
                responseClass: null,
            ),
        ], ControllerStyle::Single, null, false);

        $result = app(ControllerScaffolder::class)->scaffold($selection);

        $path = $this->temporaryDirectory.'/app/Skir/SkirController.php';
        self::assertSame([$path], $result->createdPaths);
        self::assertSame(['Skir::controller(SkirController::class)'], $result->routeHints);
        self::assertStringContainsString('public function getInvoice(string $request, SkirContext $context): ?int', (string) file_get_contents($path));

        unlink($path);
        $named = new ScaffoldingSelection([$this->method()], ControllerStyle::Single, 'App\\Skir\\Rpc\\EverythingController', false);
        $namedResult = app(ControllerScaffolder::class)->scaffold($named);

        self::assertSame(
            [$this->temporaryDirectory.'/app/Skir/Rpc/EverythingController.php'],
            $namedResult->createdPaths,
        );
    }

    #[Test]
    public function it_uses_form_requests_and_leaves_an_existing_expected_request_unchanged(): void
    {
        $existingPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';
        mkdir(dirname($existingPath), 0777, true);
        file_put_contents($existingPath, '<?php // application owned');

        $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
            [$this->method()],
            ControllerStyle::Module,
            null,
            true,
        ));

        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        self::assertSame([$controllerPath], $result->createdPaths);
        self::assertSame([$existingPath], $result->unchangedPaths);
        self::assertSame('<?php // application owned', file_get_contents($existingPath));
        self::assertStringContainsString(
            'public function getUser(GetUserFormRequest $request, SkirContext $context): User',
            (string) file_get_contents($controllerPath),
        );
        self::assertStringContainsString(
            'use App\\Http\\Requests\\Skir\\Admin\\Users\\GetUserFormRequest;',
            (string) file_get_contents($controllerPath),
        );
    }

    #[Test]
    public function it_creates_missing_form_requests_before_returning_all_created_paths(): void
    {
        $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
            [$this->method()],
            ControllerStyle::Invokable,
            null,
            true,
        ));

        $requestPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/GetUserController.php';
        self::assertEqualsCanonicalizing([$requestPath, $controllerPath], $result->createdPaths);
        self::assertFileExists($requestPath);
        self::assertFileExists($controllerPath);
    }

    #[Test]
    public function scalar_and_union_direct_types_compile_but_scalar_form_requests_fail_without_writes(): void
    {
        $direct = $this->method(
            name: 'Find',
            enumCase: 'Find',
            phpMethod: 'find',
            requestType: 'int|string|null',
            requestClass: null,
            responseType: 'string|false',
            responseClass: null,
        );
        $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
            [$direct],
            ControllerStyle::Invokable,
            null,
            false,
        ));
        self::assertStringContainsString(
            'public function __invoke(int|string|null $request, SkirContext $context): string|false',
            (string) file_get_contents($result->createdPaths[0]),
        );

        $this->removeDirectory($this->temporaryDirectory.'/app');
        mkdir($this->temporaryDirectory.'/app', 0777, true);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('does not have an object request class');

        try {
            app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
                [$direct],
                ControllerStyle::Module,
                null,
                true,
            ));
        } finally {
            self::assertSame([], glob($this->temporaryDirectory.'/app/*') ?: []);
        }
    }

    #[Test]
    public function direct_object_types_preserve_nullable_and_union_manifest_declarations(): void
    {
        $method = $this->method(
            requestType: '?GetUserRequest',
            responseType: 'User|null',
        );

        $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
            [$method],
            ControllerStyle::Invokable,
            null,
            false,
        ));

        self::assertStringContainsString(
            'public function __invoke(?GetUserRequest $request, SkirContext $context): User|null',
            (string) file_get_contents($result->createdPaths[0]),
        );
    }

    #[Test]
    public function it_aliases_colliding_imports_and_generates_php_that_compiles(): void
    {
        $selection = new ScaffoldingSelection([
            $this->method(
                requestClass: 'App\\Skir\\Admin\\Payload',
                responseType: 'Payload',
                responseClass: 'App\\Skir\\Admin\\Result\\Payload',
            ),
            $this->method(
                module: 'Billing.Invoices',
                name: 'GetInvoice',
                enumClass: 'App\\Skir\\Billing\\AdminUsersMethod',
                enumCase: 'GetInvoice',
                phpMethod: 'getInvoice',
                requestClass: 'App\\Skir\\Billing\\Payload',
                responseType: 'Payload',
                responseClass: 'App\\Skir\\Billing\\Result\\Payload',
            ),
        ], ControllerStyle::Single, null, false);

        $result = app(ControllerScaffolder::class)->scaffold($selection);
        $source = (string) file_get_contents($result->createdPaths[0]);

        self::assertMatchesRegularExpression('/use App\\\\Skir\\\\[^;]+\\\\Payload as [A-Za-z0-9_]+;/', $source);
        self::assertStringContainsString('AdminUsersMethod::GetUser', $source);
        self::assertMatchesRegularExpression('/[A-Za-z0-9_]+AdminUsersMethod::GetInvoice/', $source);

        $lint = new Process([PHP_BINARY, '-l', $result->createdPaths[0]]);
        $lint->run();
        self::assertTrue($lint->isSuccessful(), $lint->getErrorOutput().$lint->getOutput());
    }

    #[Test]
    public function duplicate_php_methods_in_one_controller_fail_before_writes(): void
    {
        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('conflicting PHP method [getUser]');

        try {
            app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection([
                $this->method(),
                $this->method(name: 'Other', enumCase: 'Other'),
            ], ControllerStyle::Module, null, false));
        } finally {
            self::assertSame([], glob($this->temporaryDirectory.'/app/*') ?: []);
        }
    }

    #[Test]
    public function controller_namespaces_and_manifest_identifiers_must_be_canonical_and_app_owned(): void
    {
        config()->set('skir-server.scaffolding.controller_namespace', 'Domain\\Skir');

        try {
            app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Module,
                null,
                false,
            ));
            self::fail('An external controller namespace should fail.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('must start with application namespace [App]', $exception->getMessage());
        }

        config()->set('skir-server.scaffolding.controller_namespace', 'App\\Skir');
        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('invalid PHP identifier');

        app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
            [$this->method(module: 'Admin...Users')],
            ControllerStyle::Module,
            null,
            false,
        ));
    }

    #[Test]
    public function all_output_is_preflighted_and_existing_controllers_are_never_overwritten(): void
    {
        $existingPath = $this->temporaryDirectory.'/app/Skir/Billing/Invoices/InvoicesController.php';
        mkdir(dirname($existingPath), 0777, true);
        file_put_contents($existingPath, '<?php // keep');

        $selection = new ScaffoldingSelection([
            $this->method(),
            $this->method(
                module: 'Billing.Invoices',
                name: 'GetInvoice',
                enumClass: 'App\\Skir\\Methods\\BillingMethod',
                enumCase: 'GetInvoice',
                phpMethod: 'getInvoice',
            ),
        ], ControllerStyle::Module, null, true);

        try {
            app(ControllerScaffolder::class)->scaffold($selection);
            self::fail('An existing controller should fail.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('already exists and was not modified', $exception->getMessage());
        }

        self::assertSame('<?php // keep', file_get_contents($existingPath));
        self::assertFileDoesNotExist($this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php');
        self::assertFileDoesNotExist($this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php');
    }

    #[Test]
    public function generated_controllers_use_normal_source_modes_and_atomic_publication_never_clobbers(): void
    {
        $previousUmask = umask(0027);

        try {
            $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Module,
                null,
                false,
            ));
        } finally {
            umask($previousUmask);
        }

        self::assertSame(0640, fileperms($result->createdPaths[0]) & 0777);

        $this->removeDirectory($this->temporaryDirectory.'/app');
        mkdir($this->temporaryDirectory.'/app', 0777, true);
        $filesystem = new ScaffoldingFilesystem;
        $publisher = new AtomicFilePublisher(
            $filesystem,
            static function (string $temporaryPath, string $destinationPath): bool {
                file_put_contents($destinationPath, '<?php // competing writer');

                return false;
            },
        );
        $scaffolder = new ControllerScaffolder(
            $publisher,
            $filesystem,
            app(FormRequestScaffolder::class),
        );

        try {
            $scaffolder->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Module,
                null,
                false,
            ));
            self::fail('A publication race should fail.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        self::assertSame('<?php // competing writer', file_get_contents($controllerPath));
        self::assertSame([], glob(dirname($controllerPath).'/.skir-*') ?: []);
    }

    private function method(
        string $module = 'Admin.Users',
        string $name = 'GetUser',
        string $enumClass = 'App\\Skir\\Methods\\AdminUsersMethod',
        string $enumCase = 'GetUser',
        string $phpMethod = 'getUser',
        string $requestType = 'GetUserRequest',
        ?string $requestClass = 'App\\Skir\\Dto\\GetUserRequest',
        string $responseType = 'User',
        ?string $responseClass = 'App\\Skir\\Dto\\User',
    ): SkirMethodDefinition {
        return new SkirMethodDefinition(
            module: $module,
            name: $name,
            enumClass: $enumClass,
            enumCase: $enumCase,
            phpMethod: $phpMethod,
            requestType: $requestType,
            requestClass: $requestClass,
            responseType: $responseType,
            responseClass: $responseClass,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
