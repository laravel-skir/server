<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Scaffolding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\AtomicFilePublisher;
use Skir\Server\Scaffolding\ControllerScaffolder;
use Skir\Server\Scaffolding\ControllerStyle;
use Skir\Server\Scaffolding\FormRequestScaffolder;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Skir\Server\Scaffolding\PhpSourceValidator;
use Skir\Server\Scaffolding\RouteRegistration;
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
        self::assertEquals([
            new RouteRegistration('App\\Skir\\Admin\\Users\\UsersController'),
        ], $result->registrations);
        self::assertSame([
            '\\Skir\\Server\\Facades\\Skir::controller(\\App\\Skir\\Admin\\Users\\UsersController::class)',
        ], $result->routeHints);
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
            '\\Skir\\Server\\Facades\\Skir::method(\\App\\Skir\\Methods\\AdminUsersMethod::GetUser, \\App\\Skir\\Admin\\Users\\GetUserController::class)',
            '\\Skir\\Server\\Facades\\Skir::method(\\App\\Skir\\Methods\\AdminUsersMethod::DeleteUser, \\App\\Skir\\Admin\\Users\\DeleteUserController::class)',
        ], $result->routeHints);
        self::assertSame('App\\Skir\\Admin\\Users\\GetUserController', $result->registrations[0]->controllerClass);
        self::assertSame('App\\Skir\\Methods\\AdminUsersMethod', $result->registrations[0]->enumClass);
        self::assertSame('GetUser', $result->registrations[0]->enumCase);
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
        self::assertSame([
            '\\Skir\\Server\\Facades\\Skir::controller(\\App\\Skir\\SkirController::class)',
        ], $result->routeHints);
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
    public function it_publishes_each_request_from_its_exact_preflight_render(): void
    {
        $formRequestStubReads = 0;
        $filesystem = new ScaffoldingFilesystem(
            static function (string $operation, string $path) use (&$formRequestStubReads): void {
                if ($operation !== 'read') {
                    return;
                }

                if (! str_ends_with($path, 'skir-form-request.stub')) {
                    return;
                }

                $formRequestStubReads++;
            },
        );
        $publisher = new AtomicFilePublisher($filesystem);
        $formRequestScaffolder = new FormRequestScaffolder($publisher, $filesystem);
        $scaffolder = new ControllerScaffolder(
            $publisher,
            $filesystem,
            $formRequestScaffolder,
            new PhpSourceValidator($filesystem),
        );

        $scaffolder->scaffold(new ScaffoldingSelection(
            [$this->method()],
            ControllerStyle::Module,
            null,
            true,
        ));

        self::assertSame(1, $formRequestStubReads);
    }

    #[Test]
    public function a_later_request_render_failure_leaves_all_preflighted_output_unpublished(): void
    {
        $formRequestStubReads = 0;
        $filesystem = new ScaffoldingFilesystem(
            static function (string $operation, string $path) use (&$formRequestStubReads): void {
                if ($operation !== 'read') {
                    return;
                }

                if (! str_ends_with($path, 'skir-form-request.stub')) {
                    return;
                }

                $formRequestStubReads++;

                if ($formRequestStubReads === 2) {
                    throw new RuntimeException('Simulated later request render failure.');
                }
            },
        );
        $publisher = new AtomicFilePublisher($filesystem);
        $formRequestScaffolder = new FormRequestScaffolder($publisher, $filesystem);
        $scaffolder = new ControllerScaffolder(
            $publisher,
            $filesystem,
            $formRequestScaffolder,
            new PhpSourceValidator($filesystem),
        );
        $selection = new ScaffoldingSelection([
            $this->method(),
            $this->method(
                name: 'UpdateUser',
                enumCase: 'UpdateUser',
                phpMethod: 'updateUser',
                requestType: 'UpdateUserRequest',
                requestClass: 'App\\Skir\\Dto\\UpdateUserRequest',
            ),
        ], ControllerStyle::Module, null, true);

        try {
            $scaffolder->scaffold($selection);
            self::fail('A later request render failure should abort preflight.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('Simulated later request render failure.', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php');
        self::assertFileDoesNotExist($this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php');
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
    public function request_and_controller_planned_for_the_same_destination_fail_before_writes(): void
    {
        $sharedClass = 'App\\Http\\Requests\\Skir\\Admin\\Users\\GetUserFormRequest';

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('resolve to the same planned destination');

        try {
            app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Single,
                $sharedClass,
                true,
            ));
        } finally {
            self::assertSame([], glob($this->temporaryDirectory.'/app/*') ?: []);
        }
    }

    #[Test]
    public function case_only_planned_controller_collisions_fail_before_writes(): void
    {
        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('resolve to the same planned destination');

        try {
            app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection([
                $this->method(),
                $this->method(
                    module: 'admin.users',
                    name: 'getuser',
                    enumClass: 'App\\Skir\\Methods\\LowerAdminUsersMethod',
                    enumCase: 'GetUser',
                    phpMethod: 'getUserLower',
                ),
            ], ControllerStyle::Invokable, null, false));
        } finally {
            self::assertSame([], glob($this->temporaryDirectory.'/app/*') ?: []);
        }
    }

    #[Test]
    #[DataProvider('invalidPhpTypeProvider')]
    public function semantically_invalid_php_types_fail_lint_before_writes(
        string $requestType,
        string $responseType,
    ): void {
        $method = $this->method(
            requestType: $requestType,
            requestClass: null,
            responseType: $responseType,
            responseClass: null,
        );

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('Rendered Skir controller');

        try {
            app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
                [$method],
                ControllerStyle::Module,
                null,
                false,
            ));
        } finally {
            self::assertSame([], glob($this->temporaryDirectory.'/app/*') ?: []);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPhpTypeProvider(): iterable
    {
        yield 'never parameter' => ['never', 'string'];
        yield 'mixed union parameter' => ['mixed|null', 'string'];
        yield 'redundant nullable return' => ['string', '?mixed'];
    }

    #[Test]
    public function a_void_request_type_creates_a_no_payload_method_with_only_context(): void
    {
        $method = $this->method(
            name: 'Ping',
            enumCase: 'Ping',
            phpMethod: 'ping',
            requestType: 'void',
            requestClass: null,
            responseType: 'string',
            responseClass: null,
        );

        $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
            [$method],
            ControllerStyle::Module,
            null,
            false,
        ));
        $source = (string) file_get_contents($result->createdPaths[0]);

        self::assertStringContainsString(
            'public function ping(SkirContext $context): string',
            $source,
        );
        self::assertStringNotContainsString('$request', $source);
    }

    #[Test]
    public function representative_output_in_every_layout_passes_php_lint(): void
    {
        foreach (ControllerStyle::cases() as $style) {
            $method = match ($style) {
                ControllerStyle::Module => $this->method(
                    requestType: 'int|string|null',
                    requestClass: null,
                    responseType: 'string|false',
                    responseClass: null,
                ),
                ControllerStyle::Invokable => $this->method(
                    requestType: '?GetUserRequest',
                    responseType: 'User|null',
                ),
                ControllerStyle::Single => $this->method(
                    requestType: 'GetUserRequest',
                    responseType: '?User',
                ),
            };
            $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
                [$method],
                $style,
                null,
                false,
            ));

            foreach ($result->createdPaths as $path) {
                $lint = new Process([PHP_BINARY, '-l', $path]);
                $lint->run();
                self::assertTrue($lint->isSuccessful(), $lint->getErrorOutput().$lint->getOutput());
            }

            $this->removeDirectory($this->temporaryDirectory.'/app');
            mkdir($this->temporaryDirectory.'/app', 0777, true);
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
            self::assertStringContainsString('does not declare expected class', $exception->getMessage());
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
            new PhpSourceValidator($filesystem),
        );
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';

        try {
            $scaffolder->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Module,
                null,
                false,
            ));
            self::fail('A publication race should fail.');
        } catch (SkirScaffoldingException $exception) {
            self::assertSame(
                "Skir controller [{$controllerPath}] already exists and was not modified.",
                $exception->getMessage(),
            );
        }

        self::assertSame('<?php // competing writer', file_get_contents($controllerPath));
        self::assertSame([], glob(dirname($controllerPath).'/.skir-*') ?: []);
    }

    #[Test]
    public function unavailable_atomic_controller_publication_uses_controller_specific_wording(): void
    {
        $filesystem = new ScaffoldingFilesystem;
        $publisher = new AtomicFilePublisher(
            $filesystem,
            static fn (string $temporaryPath, string $destinationPath): bool => false,
        );
        $scaffolder = new ControllerScaffolder(
            $publisher,
            $filesystem,
            app(FormRequestScaffolder::class),
            new PhpSourceValidator($filesystem),
        );
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';

        try {
            $scaffolder->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Module,
                null,
                false,
            ));
            self::fail('Unavailable controller publication should fail.');
        } catch (SkirScaffoldingException $exception) {
            self::assertSame(
                "Atomic publication is unavailable for Skir controller [{$controllerPath}]. Ensure the application filesystem supports same-directory hard links.",
                $exception->getMessage(),
            );
        }

        self::assertFileDoesNotExist($controllerPath);
        self::assertSame([], glob(dirname($controllerPath).'/.skir-*') ?: []);
    }

    #[Test]
    public function regeneration_adds_missing_module_methods_without_replacing_handwritten_code(): void
    {
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        mkdir(dirname($controllerPath), 0777, true);
        file_put_contents($controllerPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Skir\Admin\Users;

use App\Skir\Methods\AdminUsersMethod;
use Skir\Server\Attributes\SkirMethod;

final class UsersController
{
    #[SkirMethod(AdminUsersMethod::GetUser)]
    public function getUser(): string
    {
        return 'handwritten'; // must survive regeneration
    }
}
PHP);
        chmod($controllerPath, 0640);
        $selection = new ScaffoldingSelection([
            $this->method(),
            $this->method(name: 'DeleteUser', enumCase: 'DeleteUser', phpMethod: 'deleteUser'),
        ], ControllerStyle::Module, null, false);

        $result = app(ControllerScaffolder::class)->scaffold($selection);

        self::assertSame([], $result->createdPaths);
        self::assertSame([$controllerPath], $result->updatedPaths);
        self::assertSame([], $result->unchangedPaths);
        self::assertSame([], $result->warnings);
        self::assertSame(0640, fileperms($controllerPath) & 0777);
        self::assertStringContainsString("return 'handwritten'; // must survive regeneration", (string) file_get_contents($controllerPath));
        self::assertStringContainsString('public function deleteUser(', (string) file_get_contents($controllerPath));

        $sourceAfterFirstRun = file_get_contents($controllerPath);
        $secondResult = app(ControllerScaffolder::class)->scaffold($selection);

        self::assertSame($sourceAfterFirstRun, file_get_contents($controllerPath));
        self::assertSame([], $secondResult->updatedPaths);
        self::assertSame([$controllerPath], $secondResult->unchangedPaths);
    }

    #[Test]
    public function regeneration_adds_missing_methods_to_a_single_controller(): void
    {
        $controllerPath = $this->temporaryDirectory.'/app/Skir/RpcController.php';
        mkdir(dirname($controllerPath), 0777, true);
        file_put_contents($controllerPath, <<<'PHP'
<?php

namespace App\Skir;

final class RpcController
{
}
PHP);

        $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
            [$this->method()],
            ControllerStyle::Single,
            'App\Skir\RpcController',
            false,
        ));

        self::assertSame([$controllerPath], $result->updatedPaths);
        self::assertStringContainsString('#[SkirMethod(AdminUsersMethod::GetUser)]', (string) file_get_contents($controllerPath));
    }

    #[Test]
    public function stale_controller_methods_are_reported_but_never_deleted(): void
    {
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        mkdir(dirname($controllerPath), 0777, true);
        file_put_contents($controllerPath, <<<'PHP'
<?php

namespace App\Skir\Admin\Users;

use App\Skir\Methods\AdminUsersMethod;
use Skir\Server\Attributes\SkirMethod;

final class UsersController
{
    #[SkirMethod(AdminUsersMethod::OldMethod)]
    public function oldMethod(): string
    {
        return 'keep me';
    }
}
PHP);

        $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
            [$this->method()],
            ControllerStyle::Module,
            null,
            false,
        ));

        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('AdminUsersMethod::OldMethod', $result->warnings[0]);
        self::assertStringContainsString("return 'keep me';", (string) file_get_contents($controllerPath));
    }

    #[Test]
    public function an_existing_method_conflict_prevents_controller_and_request_writes(): void
    {
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        $requestPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';
        mkdir(dirname($controllerPath), 0777, true);
        file_put_contents($controllerPath, <<<'PHP'
<?php

namespace App\Skir\Admin\Users;

final class UsersController
{
    public function getUser(): string
    {
        return 'occupied';
    }
}
PHP);
        $original = file_get_contents($controllerPath);

        try {
            app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Module,
                null,
                true,
            ));
            self::fail('The occupied controller method should fail preflight.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('already exists without Skir identity', $exception->getMessage());
        }

        self::assertSame($original, file_get_contents($controllerPath));
        self::assertFileDoesNotExist($requestPath);
    }

    #[Test]
    public function invalid_existing_controller_php_prevents_request_writes(): void
    {
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        $requestPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';
        mkdir(dirname($controllerPath), 0777, true);
        file_put_contents($controllerPath, '<?php namespace App\Skir\Admin\Users; final class UsersController {');

        try {
            app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Module,
                null,
                true,
            ));
            self::fail('Invalid existing PHP should fail preflight.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('is invalid PHP', $exception->getMessage());
        }

        self::assertFileDoesNotExist($requestPath);
    }

    #[Test]
    public function additive_regeneration_keeps_existing_requests_and_creates_only_missing_requests(): void
    {
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        $existingRequestPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';
        $missingRequestPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/DeleteUserFormRequest.php';
        mkdir(dirname($controllerPath), 0777, true);
        mkdir(dirname($existingRequestPath), 0777, true);
        file_put_contents($controllerPath, <<<'PHP'
<?php

namespace App\Skir\Admin\Users;

final class UsersController
{
}
PHP);
        file_put_contents($existingRequestPath, '<?php // application-owned request');

        $result = app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection([
            $this->method(),
            $this->method(
                name: 'DeleteUser',
                enumCase: 'DeleteUser',
                phpMethod: 'deleteUser',
                requestType: 'DeleteUserRequest',
                requestClass: 'App\Skir\Dto\DeleteUserRequest',
            ),
        ], ControllerStyle::Module, null, true));

        self::assertSame('<?php // application-owned request', file_get_contents($existingRequestPath));
        self::assertFileExists($missingRequestPath);
        self::assertContains($existingRequestPath, $result->unchangedPaths);
        self::assertContains($missingRequestPath, $result->createdPaths);
        self::assertSame([$controllerPath], $result->updatedPaths);
    }

    #[Test]
    public function rerunning_an_invokable_controller_is_a_clean_no_op(): void
    {
        $selection = new ScaffoldingSelection(
            [$this->method()],
            ControllerStyle::Invokable,
            null,
            false,
        );
        $first = app(ControllerScaffolder::class)->scaffold($selection);
        $source = file_get_contents($first->createdPaths[0]);

        $second = app(ControllerScaffolder::class)->scaffold($selection);

        self::assertSame($source, file_get_contents($first->createdPaths[0]));
        self::assertSame([$first->createdPaths[0]], $second->unchangedPaths);
        self::assertSame([], $second->createdPaths);
        self::assertSame([], $second->updatedPaths);
    }

    #[Test]
    public function a_controller_changed_during_preflight_prevents_all_request_and_controller_writes(): void
    {
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        $requestPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';
        mkdir(dirname($controllerPath), 0777, true);
        file_put_contents($controllerPath, <<<'PHP'
<?php

namespace App\Skir\Admin\Users;

final class UsersController
{
}
PHP);
        $controllerReads = 0;
        $filesystem = new ScaffoldingFilesystem(
            static function (string $operation, string $path) use ($controllerPath, &$controllerReads): void {
                if ($operation !== 'read' || $path !== $controllerPath) {
                    return;
                }

                $controllerReads++;

                if ($controllerReads === 2) {
                    file_put_contents($controllerPath, '<?php // competing application edit');
                }
            },
        );
        $publisher = new AtomicFilePublisher($filesystem);
        $scaffolder = new ControllerScaffolder(
            $publisher,
            $filesystem,
            new FormRequestScaffolder($publisher, $filesystem),
            new PhpSourceValidator($filesystem),
        );

        try {
            $scaffolder->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Module,
                null,
                true,
            ));
            self::fail('A concurrent controller edit should fail preflight.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('changed during scaffolding', $exception->getMessage());
        }

        self::assertSame('<?php // competing application edit', file_get_contents($controllerPath));
        self::assertFileDoesNotExist($requestPath);
    }

    #[Test]
    public function a_controller_changed_at_atomic_replacement_preserves_the_edit_and_publishes_no_request(): void
    {
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        $requestPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';
        mkdir(dirname($controllerPath), 0777, true);
        file_put_contents($controllerPath, <<<'PHP'
<?php

namespace App\Skir\Admin\Users;

final class UsersController
{
}
PHP);
        $filesystem = new ScaffoldingFilesystem(
            static function (string $operation, string $path) use ($controllerPath): void {
                if ($operation === 'replaceIfUnchanged' && $path === $controllerPath) {
                    file_put_contents($controllerPath, '<?php // edit made immediately before replacement');
                }
            },
        );
        $publisher = new AtomicFilePublisher($filesystem);
        $scaffolder = new ControllerScaffolder(
            $publisher,
            $filesystem,
            new FormRequestScaffolder($publisher, $filesystem),
            new PhpSourceValidator($filesystem),
        );

        try {
            $scaffolder->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Module,
                null,
                true,
            ));
            self::fail('A controller changed at replacement should not be overwritten.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('changed during scaffolding', $exception->getMessage());
        }

        self::assertSame('<?php // edit made immediately before replacement', file_get_contents($controllerPath));
        self::assertFileDoesNotExist($requestPath);
        self::assertSame([], glob(dirname($controllerPath).'/.skir-*') ?: []);
    }

    #[Test]
    public function an_invokable_controller_with_a_different_identity_conflicts_before_writes(): void
    {
        $controllerPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/GetUserController.php';
        mkdir(dirname($controllerPath), 0777, true);
        file_put_contents($controllerPath, <<<'PHP'
<?php

namespace App\Skir\Admin\Users;

use App\Skir\Methods\AdminUsersMethod;
use Skir\Server\Attributes\SkirMethod;

final class GetUserController
{
    #[SkirMethod(AdminUsersMethod::Different)]
    public function __invoke(): string
    {
        return 'handwritten';
    }
}
PHP);
        $original = file_get_contents($controllerPath);

        try {
            app(ControllerScaffolder::class)->scaffold(new ScaffoldingSelection(
                [$this->method()],
                ControllerStyle::Invokable,
                null,
                false,
            ));
            self::fail('A stale invokable identity should conflict.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('PHP method [__invoke] already exists', $exception->getMessage());
        }

        self::assertSame($original, file_get_contents($controllerPath));
        self::assertStringNotContainsString('function getUser(', (string) file_get_contents($controllerPath));
    }

    #[Test]
    public function a_later_controller_cas_failure_rolls_back_prior_controller_updates(): void
    {
        $adminPath = $this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php';
        $billingPath = $this->temporaryDirectory.'/app/Skir/Billing/Invoices/InvoicesController.php';
        $adminRequestPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';
        $billingRequestPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Billing/Invoices/GetInvoiceFormRequest.php';
        mkdir(dirname($adminPath), 0777, true);
        mkdir(dirname($billingPath), 0777, true);
        $adminSource = <<<'PHP'
<?php

namespace App\Skir\Admin\Users;

final class UsersController
{
}
PHP;
        $billingSource = <<<'PHP'
<?php

namespace App\Skir\Billing\Invoices;

final class InvoicesController
{
}
PHP;
        file_put_contents($adminPath, $adminSource);
        file_put_contents($billingPath, $billingSource);
        $filesystem = new ScaffoldingFilesystem(
            static function (string $operation, string $path) use ($billingPath): void {
                if ($operation === 'replaceIfUnchanged' && $path === $billingPath) {
                    file_put_contents($billingPath, '<?php // competing billing edit');
                }
            },
        );
        $publisher = new AtomicFilePublisher($filesystem);
        $scaffolder = new ControllerScaffolder(
            $publisher,
            $filesystem,
            new FormRequestScaffolder($publisher, $filesystem),
            new PhpSourceValidator($filesystem),
        );

        try {
            $scaffolder->scaffold(new ScaffoldingSelection([
                $this->method(),
                $this->method(
                    module: 'Billing.Invoices',
                    name: 'GetInvoice',
                    enumClass: 'App\Skir\Methods\BillingMethod',
                    enumCase: 'GetInvoice',
                    phpMethod: 'getInvoice',
                    requestType: 'GetInvoiceRequest',
                    requestClass: 'App\Skir\Dto\GetInvoiceRequest',
                ),
            ], ControllerStyle::Module, null, true));
            self::fail('The later controller CAS should fail.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('changed during scaffolding', $exception->getMessage());
        }

        self::assertSame($adminSource, file_get_contents($adminPath));
        self::assertSame('<?php // competing billing edit', file_get_contents($billingPath));
        self::assertFileDoesNotExist($adminRequestPath);
        self::assertFileDoesNotExist($billingRequestPath);
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
