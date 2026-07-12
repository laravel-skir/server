<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Commands;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\AtomicFilePublisher;
use Skir\Server\Scaffolding\FormRequestScaffolder;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Skir\Server\Scaffolding\ScaffoldingFilesystem;
use Skir\Server\Tests\TestCase;
use Symfony\Component\Process\Process;

final class MakeSkirRequestCommandTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/skir-request-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0777, true);
        $this->app->useAppPath($this->temporaryDirectory.'/app');

        config()->set('skir-server.manifests', [$this->writeManifest([
            $this->method('GetUser', 'App\\Skir\\Admin\\GetUserRequestData'),
            $this->method('Ping', null),
        ])]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_generates_a_form_request_for_an_exact_method(): void
    {
        $expectedPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';

        $this->artisan('skir:make-request', ['method' => 'Admin.Users.GetUser'])
            ->expectsOutputToContain($expectedPath)
            ->assertSuccessful();

        self::assertFileExists($expectedPath);
        self::assertSame(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Requests\Skir\Admin\Users;

use App\Skir\Admin\GetUserRequestData;
use Illuminate\Contracts\Validation\ValidationRule;
use Skir\Server\Http\Requests\SkirFormRequest;

/** @extends SkirFormRequest<GetUserRequestData> */
final class GetUserFormRequest extends SkirFormRequest
{
    public function authorize(): bool
    {
        return false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [];
    }

    /** @return class-string<GetUserRequestData> */
    protected function skirClass(): string
    {
        return GetUserRequestData::class;
    }
}
PHP."\n", file_get_contents($expectedPath));

        $lint = new Process([PHP_BINARY, '-l', $expectedPath]);
        $lint->run();

        self::assertSame(0, $lint->getExitCode(), $lint->getErrorOutput());
    }

    public function test_name_option_changes_only_the_class_basename(): void
    {
        $expectedPath = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/ShowUserRequest.php';

        $this->artisan('skir:make-request', [
            'method' => 'Admin.Users.GetUser',
            '--name' => 'ShowUserRequest',
        ])->assertSuccessful();

        $source = file_get_contents($expectedPath);

        self::assertIsString($source);
        self::assertStringContainsString('namespace App\Http\Requests\Skir\Admin\Users;', $source);
        self::assertStringContainsString('final class ShowUserRequest extends SkirFormRequest', $source);
    }

    public function test_it_uses_a_laravel_prompt_to_select_an_object_request_method(): void
    {
        $this->artisan('skir:make-request')
            ->expectsChoice(
                'Select a Skir method',
                'Admin.Users.GetUser',
                ['Admin.Users.GetUser' => 'Admin.Users.GetUser'],
            )
            ->assertSuccessful();

        self::assertFileExists(
            $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php',
        );
    }

    public function test_missing_method_fails_when_input_is_not_interactive(): void
    {
        $this->artisan('skir:make-request', ['--no-interaction' => true])
            ->expectsOutputToContain('method is required')
            ->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_unknown_method_fails_without_writing(): void
    {
        $this->artisan('skir:make-request', ['method' => 'Admin.Users.Unknown'])
            ->expectsOutputToContain('Unknown Skir method [Admin.Users.Unknown]')
            ->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_scalar_request_method_fails_without_writing(): void
    {
        $this->artisan('skir:make-request', ['method' => 'Admin.Users.Ping'])
            ->expectsOutputToContain('does not have an object request class')
            ->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_custom_names_fail_without_writing(string $name): void
    {
        $this->artisan('skir:make-request', [
            'method' => 'Admin.Users.GetUser',
            '--name' => $name,
        ])->expectsOutputToContain('valid PHP class name')->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'path traversal' => ['../EscapedRequest'];
        yield 'namespace separator' => ['Other\\EscapedRequest'];
        yield 'directory separator' => ['Other/EscapedRequest'];
        yield 'invalid identifier' => ['2InvalidRequest'];
        yield 'reserved keyword' => ['class'];
        yield 'blank' => [''];
    }

    #[DataProvider('collidingClassNames')]
    public function test_class_names_that_collide_with_imports_are_rejected(string $name): void
    {
        $this->artisan('skir:make-request', [
            'method' => 'Admin.Users.GetUser',
            '--name' => $name,
        ])->expectsOutputToContain('collides with an imported class')->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    /** @return iterable<string, array{string}> */
    public static function collidingClassNames(): iterable
    {
        yield 'validation rule' => ['ValidationRule'];
        yield 'case-insensitive validation rule' => ['validationrule'];
        yield 'base request' => ['SkirFormRequest'];
        yield 'request DTO' => ['GetUserRequestData'];
    }

    public function test_invalid_configured_namespace_fails_without_writing(): void
    {
        config()->set('skir-server.scaffolding.request_namespace', 'App\\Http\\..\\Escaped');

        $this->artisan('skir:make-request', ['method' => 'Admin.Users.GetUser'])
            ->expectsOutputToContain('request_namespace')
            ->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_configured_namespace_outside_the_application_namespace_is_rejected(): void
    {
        config()->set('skir-server.scaffolding.request_namespace', 'Domain\\Http\\Requests');

        $this->artisan('skir:make-request', ['method' => 'Admin.Users.GetUser'])
            ->expectsOutputToContain('must start with application namespace [App]')
            ->assertFailed();

        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_existing_request_is_never_modified(): void
    {
        $path = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'preserve exactly');

        $this->artisan('skir:make-request', ['method' => 'Admin.Users.GetUser'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        self::assertSame('preserve exactly', file_get_contents($path));
    }

    public function test_render_has_no_side_effects_and_returns_absolute_destination_and_valid_source(): void
    {
        $rendered = app(FormRequestScaffolder::class)->render($this->definition());

        self::assertSame(
            $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users/GetUserFormRequest.php',
            $rendered->destinationPath,
        );
        self::assertStringContainsString('final class GetUserFormRequest', $rendered->source);
        self::assertNotEmpty((new ParserFactory)->createForHostVersion()->parse($rendered->source));
        self::assertDirectoryDoesNotExist($this->temporaryDirectory.'/app');
    }

    public function test_scaffold_atomically_writes_the_rendered_source_without_leaving_temporary_files(): void
    {
        $scaffolder = app(FormRequestScaffolder::class);
        $definition = $this->definition();
        $rendered = $scaffolder->render($definition);

        $path = $scaffolder->scaffold($definition);

        self::assertSame($rendered->destinationPath, $path);
        self::assertSame($rendered->source, file_get_contents($path));
        self::assertSame([], glob(dirname($path).'/.skir-*') ?: []);
    }

    public function test_generated_request_uses_the_normal_source_file_mode(): void
    {
        $originalUmask = umask(0027);

        try {
            $path = app(FormRequestScaffolder::class)->scaffold($this->definition());

            self::assertSame(0640, fileperms($path) & 0777);
        } finally {
            umask($originalUmask);
        }
    }

    public function test_atomic_publication_preserves_a_destination_that_exists_at_publication_time(): void
    {
        $directory = $this->temporaryDirectory.'/app/Http/Requests';
        mkdir($directory, 0777, true);
        $temporaryPath = $directory.'/.skir-race';
        $destinationPath = $directory.'/ExistingRequest.php';
        file_put_contents($temporaryPath, 'new generated bytes');
        $publisher = new AtomicFilePublisher(
            app(ScaffoldingFilesystem::class),
            static function (string $source, string $destination): bool {
                file_put_contents($destination, 'race winner bytes');

                return false;
            },
        );

        try {
            $publisher->publish($temporaryPath, $destinationPath);
            self::fail('Publication should refuse a destination created by another process.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        self::assertSame('race winner bytes', file_get_contents($destinationPath));
        self::assertFileDoesNotExist($temporaryPath);
        self::assertSame([], glob($directory.'/.skir-*') ?: []);
    }

    public function test_unavailable_atomic_publication_leaves_no_destination_or_temporary_artifact(): void
    {
        $directory = $this->temporaryDirectory.'/app/Http/Requests';
        mkdir($directory, 0777, true);
        $temporaryPath = $directory.'/.skir-unsupported';
        $destinationPath = $directory.'/UnavailableRequest.php';
        file_put_contents($temporaryPath, 'new generated bytes');
        $publisher = new AtomicFilePublisher(
            app(ScaffoldingFilesystem::class),
            static fn (string $source, string $destination): bool => false,
        );

        try {
            $publisher->publish($temporaryPath, $destinationPath);
            self::fail('Publication should fail when atomic hard links are unavailable.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('Atomic publication is unavailable', $exception->getMessage());
        }

        self::assertFileDoesNotExist($destinationPath);
        self::assertFileDoesNotExist($temporaryPath);
        self::assertSame([], glob($directory.'/.skir-*') ?: []);
    }

    #[DataProvider('filesystemFailures')]
    public function test_filesystem_failures_are_actionable_and_clean_temporary_files(string $operation): void
    {
        $filesystem = new ScaffoldingFilesystem(
            static function (string $currentOperation, string $path) use ($operation): void {
                if ($currentOperation === $operation) {
                    throw new RuntimeException("Simulated {$operation} failure for {$path}");
                }
            },
        );
        $this->app->instance(ScaffoldingFilesystem::class, $filesystem);

        $this->artisan('skir:make-request', ['method' => 'Admin.Users.GetUser'])
            ->expectsOutputToContain("Filesystem operation [{$operation}] failed")
            ->assertFailed();

        $requestDirectory = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users';
        self::assertFileDoesNotExist($requestDirectory.'/GetUserFormRequest.php');
        self::assertSame([], glob($requestDirectory.'/.skir-*') ?: []);
    }

    /** @return iterable<string, array{string}> */
    public static function filesystemFailures(): iterable
    {
        yield 'unreadable stub' => ['read'];
        yield 'directory creation' => ['makeDirectory'];
        yield 'temporary file creation' => ['temporaryFile'];
        yield 'source write' => ['write'];
        yield 'source permissions' => ['chmod'];
    }

    public function test_cleanup_failure_is_actionable_and_the_scaffolder_retries_temp_cleanup(): void
    {
        $failedOnce = false;
        $filesystem = new ScaffoldingFilesystem(
            static function (string $operation, string $path) use (&$failedOnce): void {
                if ($operation === 'remove' && ! $failedOnce) {
                    $failedOnce = true;

                    throw new RuntimeException("Simulated cleanup failure for {$path}");
                }
            },
        );
        $this->app->instance(ScaffoldingFilesystem::class, $filesystem);

        $this->artisan('skir:make-request', ['method' => 'Admin.Users.GetUser'])
            ->expectsOutputToContain('Filesystem operation [remove] failed')
            ->assertFailed();

        $requestDirectory = $this->temporaryDirectory.'/app/Http/Requests/Skir/Admin/Users';
        self::assertSame([], glob($requestDirectory.'/.skir-*') ?: []);
    }

    private function definition(): SkirMethodDefinition
    {
        return new SkirMethodDefinition(
            module: 'Admin.Users',
            name: 'GetUser',
            enumClass: 'App\\Skir\\AdminMethod',
            enumCase: 'GetUser',
            phpMethod: 'getUser',
            requestType: 'GetUserRequestData',
            requestClass: 'App\\Skir\\Admin\\GetUserRequestData',
            responseType: 'User',
            responseClass: 'App\\Skir\\Admin\\User',
        );
    }

    /** @param list<array<string, mixed>> $methods */
    private function writeManifest(array $methods): string
    {
        $path = $this->temporaryDirectory.'/manifest.json';
        $manifest = [
            'version' => 1,
            'generator' => 'test',
            'modules' => [
                [
                    'name' => 'Admin.Users',
                    'methodEnum' => 'App\\Skir\\AdminMethod',
                    'methods' => $methods,
                ],
            ],
        ];
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return array<string, mixed> */
    private function method(string $name, ?string $requestClass): array
    {
        return [
            'name' => $name,
            'enumCase' => $name,
            'phpMethod' => lcfirst($name),
            'requestType' => $name.'Request',
            'requestClass' => $requestClass,
            'responseType' => $name.'Response',
            'responseClass' => 'App\\Skir\\'.$name.'Response',
        ];
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
