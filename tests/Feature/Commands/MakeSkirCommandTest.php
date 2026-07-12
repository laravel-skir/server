<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Commands;

use Closure;
use Skir\Server\Commands\GeneratorRunner;
use Skir\Server\Scaffolding\ControllerScaffolding;
use Skir\Server\Scaffolding\ControllerScaffoldingResult;
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

    public function test_configured_defaults_are_used_when_options_are_omitted(): void
    {
        config()->set('skir-server.scaffolding.controller_style', 'single');
        config()->set('skir-server.scaffolding.single_controller', 'App\\Rpc\\ConfiguredController');
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

    public function test_unknown_selector_is_rejected_before_generator_when_current_manifests_are_readable(): void
    {
        $runner = new RecordingGeneratorRunner;
        $this->app->instance(GeneratorRunner::class, $runner);

        $this->artisan('skir:make', [
            '--generate' => true,
            '--method' => ['Missing.Method'],
            '--no-interaction' => true,
        ])->expectsOutputToContain('Unknown Skir method')->assertFailed();

        self::assertSame([], $runner->commands);
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
            ->expectsOutputToContain('generator output')
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
        $this->artisan('skir:make')
            ->expectsChoice('Select Skir modules or methods', ['method:Admin.Users.GetUser'], [
                'module:Admin.Users' => 'Module: Admin.Users',
                'module:Status.Health' => 'Module: Status.Health',
                'method:Admin.Users.GetUser' => 'Method: Admin.Users.GetUser',
                'method:Admin.Users.DeleteUser' => 'Method: Admin.Users.DeleteUser',
                'method:Status.Health.Ping' => 'Method: Status.Health.Ping',
            ])
            ->expectsChoice('Controller style', 'module', [
                'module' => 'One controller per module',
                'invokable' => 'One invokable controller per method',
                'single' => 'One controller for this selection',
            ])
            ->expectsConfirmation('Generate form requests?', 'yes')
            ->expectsConfirmation('Create these files?', 'no')
            ->expectsOutputToContain('Scaffolding cancelled')
            ->assertSuccessful();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
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
        $output('out', 'generator output');
        ($this->beforeReturn)?->__invoke();

        return $this->exitCode;
    }
}
