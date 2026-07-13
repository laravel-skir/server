<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Commands;

use Closure;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Skir\Server\Commands\GeneratorRunner;
use Skir\Server\Commands\SymfonyGeneratorRunner;
use Skir\Server\Scaffolding\ControllerScaffolding;
use Skir\Server\Scaffolding\ControllerScaffoldingResult;
use Skir\Server\Scaffolding\Manifest\ManifestRepository;
use Skir\Server\Scaffolding\RouteRegistration;
use Skir\Server\Tests\TestCase;

final class MakeSkirCommandTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/skir-make-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0777, true);
        $this->app->useAppPath($this->temporaryDirectory.'/app');
        config()->set('skir-server.manifests', [$this->manifestPath()]);
        $this->writeManifest($this->defaultModules());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_non_interactive_invocation_requires_a_concrete_selection(): void
    {
        $this->artisan('skir:make', ['--no-interaction' => true])
            ->expectsOutputToContain('selection is required')
            ->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_option_conflicts_are_rejected_before_the_generator_runs(): void
    {
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--all' => true,
            '--module' => ['Admin.Users'],
            '--with-requests' => true,
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('cannot be used together')->assertFailed();

        self::assertSame([], $runner->commands);
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_it_scaffolds_repeated_method_selectors_with_explicit_options(): void
    {
        $this->artisan('skir:make', [
            '--method' => ['Admin.Users.GetUser', 'Status.Health.Ping'],
            '--style' => 'invokable',
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('Selected RPC methods')
            ->expectsOutputToContain('Admin.Users.GetUser')
            ->expectsOutputToContain('Status.Health.Ping')
            ->expectsOutputToContain('Created')
            ->expectsOutputToContain('Route registrations')
            ->assertSuccessful();

        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Admin/Users/GetUserController.php');
        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Status/Health/PingController.php');
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app/Http/Requests');
    }

    public function test_all_three_controller_styles_and_custom_single_controller_are_supported(): void
    {
        $this->artisan('skir:make', [
            '--module' => ['Admin.Users'],
            '--style' => 'module',
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php');

        $this->artisan('skir:make', [
            '--method' => ['Status.Health.Ping'],
            '--style' => 'invokable',
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Status/Health/PingController.php');

        $this->artisan('skir:make', [
            '--method' => ['Admin.Users.DeleteUser'],
            '--style' => 'single',
            '--controller' => 'App\\Rpc\\BackofficeController',
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();
        self::assertFileExists($this->temporaryDirectory.'/app/Rpc/BackofficeController.php');
    }

    public function test_producer_normalized_module_identities_remain_distinct_through_selection_and_scaffolding(): void
    {
        $this->writeManifest([
            $this->module('_Root', 'Skir\\RootSkirMethod', [
                $this->method('RootLevel', 'RootLevel', 'Skir\\RootLevelRequest'),
            ]),
            $this->module('Root', 'Skir\\Root\\RootSkirMethod', [
                $this->method('DirectoryRoot', 'DirectoryRoot', 'Skir\\Root\\DirectoryRootRequest'),
            ]),
            $this->module('AdminUsers', 'Skir\\AdminUsers\\AdminUsersSkirMethod', [
                $this->method('LiteralDirectory', 'LiteralDirectory', 'Skir\\AdminUsers\\LiteralDirectoryRequest'),
            ]),
            $this->module('Admin.Users', 'Skir\\Admin\\Users\\UsersSkirMethod', [
                $this->method('NestedDirectory', 'NestedDirectory', 'Skir\\Admin\\Users\\NestedDirectoryRequest'),
            ]),
            $this->module('UserProfile', 'Skir\\UserProfile\\UserProfileSkirMethod', [
                $this->method('GetProfile', 'GetProfile', 'Skir\\UserProfile\\GetProfileRequest'),
            ]),
        ]);

        $repository = app(ManifestRepository::class);

        self::assertSame([
            '_Root.RootLevel',
            'Root.DirectoryRoot',
            'AdminUsers.LiteralDirectory',
            'Admin.Users.NestedDirectory',
            'UserProfile.GetProfile',
        ], array_keys($repository->methods()));

        $this->artisan('skir:make', [
            '--all' => true,
            '--style' => 'module',
            '--with-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('_Root.RootLevel')
            ->expectsOutputToContain('Root.DirectoryRoot')
            ->expectsOutputToContain('AdminUsers.LiteralDirectory')
            ->expectsOutputToContain('Admin.Users.NestedDirectory')
            ->expectsOutputToContain('UserProfile.GetProfile')
            ->assertSuccessful();

        foreach ([
            'app/Skir/_Root/_RootController.php',
            'app/Skir/Root/RootController.php',
            'app/Skir/AdminUsers/AdminUsersController.php',
            'app/Skir/Admin/Users/UsersController.php',
            'app/Skir/UserProfile/UserProfileController.php',
            'app/Http/Requests/Skir/_Root/RootLevelFormRequest.php',
            'app/Http/Requests/Skir/Root/DirectoryRootFormRequest.php',
            'app/Http/Requests/Skir/AdminUsers/LiteralDirectoryFormRequest.php',
            'app/Http/Requests/Skir/Admin/Users/NestedDirectoryFormRequest.php',
            'app/Http/Requests/Skir/UserProfile/GetProfileFormRequest.php',
        ] as $path) {
            self::assertFileExists($this->temporaryDirectory.'/'.$path);
        }

        self::assertStringContainsString(
            'namespace App\\Skir\\UserProfile;',
            (string) file_get_contents($this->temporaryDirectory.'/app/Skir/UserProfile/UserProfileController.php'),
        );
        self::assertStringContainsString(
            'namespace App\\Http\\Requests\\Skir\\UserProfile;',
            (string) file_get_contents($this->temporaryDirectory.'/app/Http/Requests/Skir/UserProfile/GetProfileFormRequest.php'),
        );

        $this->artisan('skir:make', [
            '--method' => ['UserProfile.GetProfile', '_Root.RootLevel'],
            '--style' => 'module',
            '--with-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('Unchanged')
            ->assertSuccessful();

        self::assertSame([
            '_Root.RootLevel',
            'Root.DirectoryRoot',
            'AdminUsers.LiteralDirectory',
            'Admin.Users.NestedDirectory',
            'UserProfile.GetProfile',
        ], array_keys($repository->methods()));
    }

    public function test_request_flags_override_the_configured_default(): void
    {
        config()->set('skir-server.scaffolding.form_requests', false);

        $this->artisan('skir:make', [
            '--method' => ['Admin.Users.GetUser'],
            '--with-requests' => true,
            '--style' => 'module',
            '--no-interaction' => true,
        ])->assertSuccessful();

        self::assertFileExists(
            $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php',
        );
    }

    public function test_scalar_only_selection_succeeds_with_form_request_preference_and_keeps_direct_signature(): void
    {
        config()->set('skir-server.scaffolding.form_requests', true);

        $this->artisan('skir:make', [
            '--method' => ['Status.Health.Ping'],
            '--with-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $controller = file_get_contents(
            $this->temporaryDirectory.'/app/Skir/Status/Health/HealthController.php',
        );
        self::assertIsString($controller);
        self::assertStringContainsString('ping(string $request, SkirContext $context)', $controller);
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app/Http/Requests');
    }

    public function test_invalid_request_namespace_fails_before_generator_even_for_an_eventually_scalar_selection(): void
    {
        config()->set('skir-server.scaffolding.request_namespace', 'Domain\\Invalid');
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--method' => ['Status.Health.Ping'],
            '--with-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('request_namespace')->assertFailed();

        self::assertSame([], $runner->commands);
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_all_with_form_requests_succeeds_and_generates_only_eligible_requests(): void
    {
        $this->artisan('skir:make', [
            '--all' => true,
            '--with-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        self::assertFileExists(
            $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php',
        );
        self::assertFileDoesNotExist(
            $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/DeleteUserFormRequest.php',
        );
        self::assertFileDoesNotExist(
            $this->temporaryDirectory.'/app/Http/Requests/Skir/Status/Health/PingFormRequest.php',
        );
        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php');
        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Status/Health/HealthController.php');
    }

    public function test_configured_defaults_are_used_when_options_are_omitted(): void
    {
        config()->set('skir-server.scaffolding.controller_style', 'single');
        config()->set('skir-server.scaffolding.controller_namespace', 'Domain\\Unused');
        config()->set('skir-server.scaffolding.single_controller', '\\App\\Rpc\\ConfiguredController\\');
        config()->set('skir-server.scaffolding.form_requests', false);

        $this->artisan('skir:make', [
            '--method' => ['Admin.Users.GetUser'],
            '--no-interaction' => true,
        ])->assertSuccessful();

        self::assertFileExists($this->temporaryDirectory.'/app/Rpc/ConfiguredController.php');
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app/Http/Requests');
    }

    public function test_unknown_and_ambiguous_selectors_fail_without_writing(): void
    {
        $this->writeManifest([
            ...$this->defaultModules(),
            $this->module('ADMIN.USERS', 'App\\Skir\\OtherMethod', [
                $this->method('GetOther', 'GetOther', null),
            ]),
        ]);

        $this->artisan('skir:make', [
            '--module' => ['admin.users'],
            '--no-interaction' => true,
        ])->expectsOutputToContain('ambiguous')->assertFailed();

        $this->artisan('skir:make', [
            '--method' => ['Missing.Method'],
            '--no-interaction' => true,
        ])->expectsOutputToContain('Unknown Skir method')->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_generator_can_introduce_the_selected_method_before_fresh_manifest_validation(): void
    {
        $runner = new RecordingGeneratorRunner(function (): void {
            $this->writeManifest([
                ...$this->defaultModules(),
                $this->module('Generated.Reports', 'App\\Skir\\ReportMethod', [
                    $this->method('Export', 'Export', null),
                ]),
            ]);
        });
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--method' => ['Generated.Reports.Export'],
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        self::assertCount(1, $runner->commands);
        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Generated/Reports/ReportsController.php');
    }

    public function test_invalid_style_and_controller_combinations_fail_without_writing(): void
    {
        $this->artisan('skir:make', [
            '--all' => true,
            '--style' => 'resource',
            '--no-interaction' => true,
        ])->expectsOutputToContain('not supported')->assertFailed();

        $this->artisan('skir:make', [
            '--all' => true,
            '--style' => 'module',
            '--controller' => 'App\\Rpc\\EverythingController',
            '--no-interaction' => true,
        ])->expectsOutputToContain('only be used with')->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_invalid_custom_controller_is_rejected_before_generator_execution(): void
    {
        foreach (['', 'Domain\\EscapedController', 'App\\Rpc\\../EscapedController'] as $controller) {
            $runner = new RecordingGeneratorRunner;
            $this->app->instance(GeneratorRunner::class, $runner);

            $this->artisan('skir:make', [
                '--generate' => true,
                '--all' => true,
                '--style' => 'single',
                '--controller' => $controller,
                '--no-interaction' => true,
            ])->expectsOutputToContain('Single Skir controller')->assertFailed();

            self::assertSame([], $runner->commands);
        }
    }

    public function test_applicable_invalid_scaffolding_configuration_is_rejected_before_generator(): void
    {
        $cases = [
            'default controller style' => [
                'skir-server.scaffolding.controller_style',
                'resource',
                ['--without-requests' => true],
                'not supported',
            ],
            'controller namespace' => [
                'skir-server.scaffolding.controller_namespace',
                'Domain\\Skir',
                ['--style' => 'module', '--without-requests' => true],
                'controller namespace',
            ],
            'request namespace' => [
                'skir-server.scaffolding.request_namespace',
                'Domain\\Requests',
                ['--style' => 'module', '--with-requests' => true],
                'request_namespace',
            ],
            'single controller' => [
                'skir-server.scaffolding.single_controller',
                'Domain\\EscapedController',
                ['--style' => 'single', '--without-requests' => true],
                'Single Skir controller',
            ],
            'form request preference' => [
                'skir-server.scaffolding.form_requests',
                'yes',
                ['--style' => 'module'],
                'form_requests',
            ],
        ];

        foreach ($cases as [$key, $value, $options, $message]) {
            config()->set($key, $value);
            $runner = new RecordingGeneratorRunner;
            $this->app->instance(GeneratorRunner::class, $runner);

            $this->artisan('skir:make', [
                '--generate' => true,
                '--all' => true,
                '--no-interaction' => true,
                ...$options,
            ])->expectsOutputToContain($message)->assertFailed();

            self::assertSame([], $runner->commands);
            config()->set($key, data_get(require dirname(__DIR__, 3).'/config/skir-server.php', substr($key, 12)));
        }
    }

    public function test_explicit_flags_override_unused_invalid_request_configuration(): void
    {
        config()->set('skir-server.scaffolding.form_requests', 'invalid');
        config()->set('skir-server.scaffolding.request_namespace', 'Domain\\Invalid');
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--all' => true,
            '--style' => 'module',
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        self::assertCount(1, $runner->commands);
    }

    public function test_generate_runs_configured_argv_from_base_path_without_a_timeout_and_reloads_manifests(): void
    {
        unlink($this->manifestPath());
        $runner = new RecordingGeneratorRunner(function (): void {
            $this->writeManifest($this->defaultModules());
        });
        $this->app->instance(GeneratorRunner::class, $runner);
        config()->set('skir-server.generator_command', ['bun', 'run', 'skir:generate']);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--method' => ['Status.Health.Ping'],
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('Generating Skir definitions')
            ->expectsOutputToContain('<info>raw stdout</info>')
            ->assertSuccessful();

        self::assertSame([['bun', 'run', 'skir:generate']], $runner->commands);
        self::assertSame([base_path()], $runner->workingDirectories);
        self::assertSame([null], $runner->timeouts);
        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Status/Health/HealthController.php');
    }

    public function test_generate_reloads_a_manifest_that_was_prevalidated_and_cached(): void
    {
        $runner = new RecordingGeneratorRunner(function (): void {
            $modules = $this->defaultModules();
            $modules[0]['methods'][0]['requestType'] = 'App\\Skir\\RegeneratedRequestData';
            $modules[0]['methods'][0]['requestClass'] = 'App\\Skir\\RegeneratedRequestData';
            $this->writeManifest($modules);
        });
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--method' => ['Admin.Users.GetUser'],
            '--with-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $request = file_get_contents(
            $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php',
        );
        self::assertIsString($request);
        self::assertStringContainsString('use App\\Skir\\RegeneratedRequestData;', $request);
        self::assertStringNotContainsString('GetUserRequestData', $request);
    }

    public function test_generator_failure_returns_its_status_and_never_writes_from_a_stale_manifest(): void
    {
        $runner = new RecordingGeneratorRunner(exitCode: 7);
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--all' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('failed with exit code [7]')->assertExitCode(7);

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_generator_failure_does_not_load_an_invalid_stale_manifest(): void
    {
        file_put_contents($this->manifestPath(), 'invalid stale manifest');
        $runner = new RecordingGeneratorRunner(exitCode: 9);
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--method' => ['Generated.Later'],
            '--no-interaction' => true,
        ])->expectsOutputToContain('failed with exit code [9]')->assertExitCode(9);

        self::assertCount(1, $runner->commands);
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_generator_configuration_must_be_a_non_empty_argv_list(): void
    {
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);
        config()->set('skir-server.generator_command', 'bun run skir:generate');

        $this->artisan('skir:make', [
            '--generate' => true,
            '--all' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('argument list')->assertFailed();

        self::assertSame([], $runner->commands);
    }

    public function test_generator_configuration_rejects_whitespace_only_arguments(): void
    {
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);
        config()->set('skir-server.generator_command', ['npx', '   ']);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--all' => true,
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('non-empty string arguments')->assertFailed();

        self::assertSame([], $runner->commands);
    }

    public function test_generator_runner_exception_fails_without_loading_manifests_or_scaffolding(): void
    {
        file_put_contents($this->manifestPath(), 'invalid stale manifest');
        $this->app->instance(GeneratorRunner::class, new ThrowingGeneratorRunner);

        $status = Artisan::call('skir:make', [
            '--generate' => true,
            '--method' => ['Generated.Later'],
            '--without-requests' => true,
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(1, $status);
        self::assertStringContainsString('failed to start or run', $output);
        self::assertStringContainsString(RuntimeException::class, $output);
        self::assertStringNotContainsString('process unavailable', $output);
        self::assertStringNotContainsString('secret-token', $output);
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_result_categories_warnings_and_structured_routes_are_rendered(): void
    {
        $scaffolder = $this->createStub(ControllerScaffolding::class);
        $scaffolder->method('scaffold')->willReturn(new ControllerScaffoldingResult(
            createdPaths: ['/tmp/created.php'],
            unchangedPaths: ['/tmp/unchanged.php'],
            registrations: [new RouteRegistration(
                'App\\Skir\\PingController',
                'App\\Skir\\HealthMethod',
                'Ping',
            )],
            updatedPaths: ['/tmp/updated.php'],
            warnings: ['A stale method remains.'],
        ));
        $this->app->instance(ControllerScaffolding::class, $scaffolder);

        $this->artisan('skir:make', [
            '--method' => ['Status.Health.Ping'],
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('Created: /tmp/created.php')
            ->expectsOutputToContain('Updated: /tmp/updated.php')
            ->expectsOutputToContain('Unchanged: /tmp/unchanged.php')
            ->expectsOutputToContain('Warning: A stale method remains.')
            ->expectsOutputToContain('App\\Skir\\HealthMethod::Ping')
            ->assertSuccessful();
    }

    public function test_no_op_rerun_is_successful_and_reports_unchanged_files(): void
    {
        $arguments = [
            '--method' => ['Status.Health.Ping'],
            '--without-requests' => true,
            '--no-interaction' => true,
        ];

        $this->artisan('skir:make', $arguments)->assertSuccessful();
        $this->artisan('skir:make', $arguments)
            ->expectsOutputToContain('Unchanged')
            ->assertSuccessful();
    }

    public function test_interactive_confirmation_can_abort_without_writing(): void
    {
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', ['--generate' => true])
            ->expectsChoice('Controller style', 'module', [
                'module' => 'One controller per module',
                'invokable' => 'One invokable controller per method',
                'single' => 'One controller for this selection',
            ])
            ->expectsConfirmation('Generate form requests?', 'yes')
            ->expectsChoice('Select Skir modules or methods', ['method:Admin.Users.GetUser'], [
                'module:Admin.Users' => 'Module: Admin.Users',
                'module:Status.Health' => 'Module: Status.Health',
                'method:Admin.Users.GetUser' => 'Method: Admin.Users.GetUser',
                'method:Admin.Users.DeleteUser' => 'Method: Admin.Users.DeleteUser',
                'method:Status.Health.Ping' => 'Method: Status.Health.Ping',
            ])
            ->expectsConfirmation('Create these files?', 'no')
            ->expectsOutputToContain('No controller or request files were written')
            ->assertSuccessful();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
        self::assertCount(1, $runner->commands);
    }

    public function test_all_and_repeated_module_selectors_scaffold_each_method_once(): void
    {
        $this->artisan('skir:make', [
            '--module' => ['Admin.Users', 'Admin.Users'],
            '--style' => 'module',
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $source = file_get_contents($this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php');
        self::assertIsString($source);
        self::assertSame(1, substr_count($source, 'function getUser('));
        self::assertSame(1, substr_count($source, 'function deleteUser('));

        $this->removeDirectory($this->temporaryDirectory.'/app');

        $this->artisan('skir:make', [
            '--all' => true,
            '--style' => 'module',
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php');
        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Status/Health/HealthController.php');
    }

    public function test_interactive_single_controller_entry_is_used(): void
    {
        config()->set('skir-server.scaffolding.controller_namespace', 'Domain\\Unused');

        $this->artisan('skir:make')
            ->expectsChoice('Controller style', 'single', [
                'module' => 'One controller per module',
                'invokable' => 'One invokable controller per method',
                'single' => 'One controller for this selection',
            ])
            ->expectsQuestion('Single controller class', '\\App\\Rpc\\InteractiveController\\')
            ->expectsConfirmation('Generate form requests?', 'no')
            ->expectsChoice('Select Skir modules or methods', ['method:Admin.Users.GetUser'], [
                'module:Admin.Users' => 'Module: Admin.Users',
                'module:Status.Health' => 'Module: Status.Health',
                'method:Admin.Users.GetUser' => 'Method: Admin.Users.GetUser',
                'method:Admin.Users.DeleteUser' => 'Method: Admin.Users.DeleteUser',
                'method:Status.Health.Ping' => 'Method: Status.Health.Ping',
            ])
            ->expectsOutputToContain('single (App\\Rpc\\InteractiveController)')
            ->expectsConfirmation('Create these files?', 'yes')
            ->assertSuccessful();

        self::assertFileExists($this->temporaryDirectory.'/app/Rpc/InteractiveController.php');
    }

    public function test_controller_option_implies_single_style_without_using_the_configured_style(): void
    {
        config()->set('skir-server.scaffolding.controller_style', 'module');

        $this->artisan('skir:make', [
            '--method' => ['Admin.Users.GetUser'],
            '--controller' => 'App\\Rpc\\ImpliedSingleController',
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        self::assertFileExists($this->temporaryDirectory.'/app/Rpc/ImpliedSingleController.php');
        self::assertFileDoesNotExist($this->temporaryDirectory.'/app/Skir/Admin/Users/UsersController.php');
    }

    public function test_controller_option_skips_the_interactive_style_and_controller_prompts(): void
    {
        $this->artisan('skir:make', [
            '--method' => ['Admin.Users.GetUser'],
            '--controller' => 'App\\Rpc\\ExplicitInteractiveController',
            '--without-requests' => true,
        ])->expectsConfirmation('Create these files?', 'yes')
            ->assertSuccessful();

        self::assertFileExists($this->temporaryDirectory.'/app/Rpc/ExplicitInteractiveController.php');
    }

    public function test_invalid_configured_single_controller_does_not_block_interactive_module_choice(): void
    {
        config()->set('skir-server.scaffolding.controller_style', 'single');
        config()->set('skir-server.scaffolding.single_controller', 'Domain\\InvalidController');
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--without-requests' => true,
        ])->expectsChoice('Controller style', 'module', [
            'module' => 'One controller per module',
            'invokable' => 'One invokable controller per method',
            'single' => 'One controller for this selection',
        ])->expectsChoice('Select Skir modules or methods', ['method:Status.Health.Ping'], [
            'module:Admin.Users' => 'Module: Admin.Users',
            'module:Status.Health' => 'Module: Status.Health',
            'method:Admin.Users.GetUser' => 'Method: Admin.Users.GetUser',
            'method:Admin.Users.DeleteUser' => 'Method: Admin.Users.DeleteUser',
            'method:Status.Health.Ping' => 'Method: Status.Health.Ping',
        ])->expectsConfirmation('Create these files?', 'yes')
            ->assertSuccessful();

        self::assertCount(1, $runner->commands);
        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Status/Health/HealthController.php');
    }

    public function test_invalid_interactive_single_controller_fails_before_generator(): void
    {
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--without-requests' => true,
        ])->expectsChoice('Controller style', 'single', [
            'module' => 'One controller per module',
            'invokable' => 'One invokable controller per method',
            'single' => 'One controller for this selection',
        ])->expectsQuestion('Single controller class', 'Domain\\InvalidController')
            ->expectsOutputToContain('Single Skir controller')
            ->assertFailed();

        self::assertSame([], $runner->commands);
    }

    public function test_fully_qualified_single_controller_skips_unused_controller_namespace_configuration(): void
    {
        config()->set('skir-server.scaffolding.controller_namespace', 'Domain\\Invalid');
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--method' => ['Status.Health.Ping'],
            '--controller' => '\\App\\Rpc\\CanonicalController\\',
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('single (App\\Rpc\\CanonicalController)')
            ->assertSuccessful();

        self::assertCount(1, $runner->commands);
        self::assertFileExists($this->temporaryDirectory.'/app/Rpc/CanonicalController.php');
    }

    public function test_interactive_method_selection_uses_the_reloaded_generated_manifest(): void
    {
        $runner = new RecordingGeneratorRunner(function (): void {
            $this->writeManifest([
                $this->module('Generated.Reports', 'App\\Skir\\ReportMethod', [
                    $this->method('Export', 'Export', null),
                ]),
            ]);
        });
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--without-requests' => true,
        ])->expectsChoice('Controller style', 'module', [
            'module' => 'One controller per module',
            'invokable' => 'One invokable controller per method',
            'single' => 'One controller for this selection',
        ])->expectsChoice('Select Skir modules or methods', ['method:Generated.Reports.Export'], [
            'module:Generated.Reports' => 'Module: Generated.Reports',
            'method:Generated.Reports.Export' => 'Method: Generated.Reports.Export',
        ])->expectsConfirmation('Create these files?', 'yes')
            ->assertSuccessful();

        self::assertFileExists($this->temporaryDirectory.'/app/Skir/Generated/Reports/ReportsController.php');
    }

    public function test_generator_output_is_written_raw_for_stdout_and_stderr(): void
    {
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--method' => ['Status.Health.Ping'],
            '--without-requests' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('<info>raw stdout</info>')
            ->expectsOutputToContain('<error>raw stderr</error>')
            ->assertSuccessful();
    }

    public function test_symfony_generator_runner_streams_output_and_returns_the_process_status(): void
    {
        $output = '';
        $status = (new SymfonyGeneratorRunner)->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "generated"); exit(4);'],
            $this->temporaryDirectory,
            null,
            static function (string $type, string $buffer) use (&$output): void {
                $output .= $buffer;
            },
        );

        self::assertSame(4, $status);
        self::assertSame('generated', $output);
    }

    /** @return list<array<string, mixed>> */
    private function defaultModules(): array
    {
        return [
            $this->module('Admin.Users', 'App\\Skir\\AdminUsersMethod', [
                $this->method('GetUser', 'GetUser', 'App\\Skir\\GetUserRequestData'),
                $this->method('DeleteUser', 'DeleteUser', null),
            ]),
            $this->module('Status.Health', 'App\\Skir\\HealthMethod', [
                $this->method('Ping', 'Ping', null),
            ]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $methods
     * @return array<string, mixed>
     */
    private function module(string $name, string $enum, array $methods): array
    {
        return ['name' => $name, 'methodEnum' => $enum, 'methods' => $methods];
    }

    /** @return array<string, mixed> */
    private function method(string $name, string $enumCase, ?string $requestClass): array
    {
        return [
            'name' => $name,
            'enumCase' => $enumCase,
            'phpMethod' => lcfirst($name),
            'requestType' => $requestClass === null ? 'string' : $requestClass,
            'requestClass' => $requestClass,
            'responseType' => 'string',
            'responseClass' => null,
        ];
    }

    /** @param list<array<string, mixed>> $modules */
    private function writeManifest(array $modules): void
    {
        file_put_contents($this->manifestPath(), json_encode([
            'version' => 1,
            'generator' => 'php',
            'modules' => $modules,
        ], JSON_THROW_ON_ERROR));
    }

    private function manifestPath(): string
    {
        return $this->temporaryDirectory.'/manifest.json';
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

final class RecordingGeneratorRunner implements GeneratorRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var list<string> */
    public array $workingDirectories = [];

    /** @var list<float|null> */
    public array $timeouts = [];

    public function __construct(
        private readonly ?Closure $beforeReturn = null,
        private readonly int $exitCode = 0,
    ) {}

    public function run(array $command, string $workingDirectory, ?float $timeout, Closure $output): int
    {
        $this->commands[] = $command;
        $this->workingDirectories[] = $workingDirectory;
        $this->timeouts[] = $timeout;
        $output('out', '<info>raw stdout</info>');
        $output('err', '<error>raw stderr</error>');
        ($this->beforeReturn)?->__invoke();

        return $this->exitCode;
    }
}

final class ThrowingGeneratorRunner implements GeneratorRunner
{
    public function run(array $command, string $workingDirectory, ?float $timeout, Closure $output): int
    {
        throw new RuntimeException('process unavailable secret-token');
    }
}
