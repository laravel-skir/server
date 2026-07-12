<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Commands;

use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Skir\Server\Scaffolding\FormRequestScaffolder;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Skir\Server\Tests\TestCase;

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

    protected function skirClass(): string
    {
        return GetUserRequestData::class;
    }
}
PHP."\n", file_get_contents($expectedPath));
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

    public function test_invalid_configured_namespace_fails_without_writing(): void
    {
        config()->set('skir-server.scaffolding.request_namespace', 'App\\Http\\..\\Escaped');

        $this->artisan('skir:make-request', ['method' => 'Admin.Users.GetUser'])
            ->expectsOutputToContain('request_namespace')
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
