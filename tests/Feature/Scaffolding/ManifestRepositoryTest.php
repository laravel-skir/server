<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Scaffolding;

use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\Manifest\ManifestRepository;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Skir\Server\Scaffolding\Manifest\SkirModuleDefinition;
use Skir\Server\SkirServerServiceProvider;
use Skir\Server\Tests\TestCase;

final class ManifestRepositoryTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/skir-server-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_it_reads_a_valid_manifest_into_exact_value_objects(): void
    {
        $path = $this->writeManifest('manifest.json', $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', [
                $this->method('GetUser', 'GetUser', 'getUser', 'GetUserRequest', 'App\\Skir\\GetUserRequest', 'User', 'App\\Skir\\User'),
            ]),
        ]));
        config()->set('skir-server.manifests', [$path]);

        $repository = app(ManifestRepository::class);

        self::assertEquals([
            'Admin.GetUser' => new SkirMethodDefinition(
                module: 'Admin',
                name: 'GetUser',
                enumClass: 'App\\Skir\\AdminMethod',
                enumCase: 'GetUser',
                phpMethod: 'getUser',
                requestType: 'GetUserRequest',
                requestClass: 'App\\Skir\\GetUserRequest',
                responseType: 'User',
                responseClass: 'App\\Skir\\User',
            ),
        ], $repository->methods());
        self::assertEquals([
            new SkirModuleDefinition(
                name: 'Admin',
                enumClass: 'App\\Skir\\AdminMethod',
                methods: array_values($repository->methods()),
            ),
        ], $repository->modules());
        self::assertSame('Admin.GetUser', $repository->methods()['Admin.GetUser']->id());
    }

    public function test_it_merges_manifests_in_configured_module_and_method_order(): void
    {
        $firstPath = $this->writeManifest('first.json', $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', [
                $this->method('GetUser', 'GetUser', 'getUser'),
                $this->method('DeleteUser', 'DeleteUser', 'deleteUser'),
            ]),
        ]));
        $secondPath = $this->writeManifest('second.json', $this->manifest([
            $this->module('PublicApi', 'App\\Skir\\PublicApiMethod', [
                $this->method('Health', 'Health', 'health'),
            ]),
        ]));
        config()->set('skir-server.manifests', [$firstPath, $secondPath]);

        $repository = app(ManifestRepository::class);

        self::assertSame(['Admin.GetUser', 'Admin.DeleteUser', 'PublicApi.Health'], array_keys($repository->methods()));
        self::assertSame(['Admin', 'PublicApi'], array_map(
            static fn (SkirModuleDefinition $module): string => $module->name,
            $repository->modules(),
        ));
    }

    public function test_it_merges_repeated_modules_with_the_same_enum_within_one_manifest(): void
    {
        $path = $this->writeManifest('manifest.json', $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', [
                $this->method('GetUser', 'GetUser', 'getUser'),
            ]),
            $this->module('PublicApi', 'App\\Skir\\PublicApiMethod', [
                $this->method('Health', 'Health', 'health'),
            ]),
            $this->module('Admin', 'App\\Skir\\AdminMethod', [
                $this->method('DeleteUser', 'DeleteUser', 'deleteUser'),
            ]),
        ]));
        config()->set('skir-server.manifests', [$path]);

        $modules = app(ManifestRepository::class)->modules();

        self::assertSame(['Admin', 'PublicApi'], array_column($modules, 'name'));
        self::assertSame(
            ['Admin.GetUser', 'Admin.DeleteUser'],
            array_map(static fn (SkirMethodDefinition $method): string => $method->id(), $modules[0]->methods),
        );
    }

    public function test_it_merges_repeated_modules_with_the_same_enum_across_manifests(): void
    {
        $firstPath = $this->writeManifest('first.json', $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', [
                $this->method('GetUser', 'GetUser', 'getUser'),
            ]),
        ]));
        $secondPath = $this->writeManifest('second.json', $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', [
                $this->method('DeleteUser', 'DeleteUser', 'deleteUser'),
            ]),
        ]));
        config()->set('skir-server.manifests', [$firstPath, $secondPath]);

        $modules = app(ManifestRepository::class)->modules();

        self::assertCount(1, $modules);
        self::assertSame(
            ['Admin.GetUser', 'Admin.DeleteUser'],
            array_map(static fn (SkirMethodDefinition $method): string => $method->id(), $modules[0]->methods),
        );
    }

    public function test_it_rejects_repeated_modules_with_different_enums(): void
    {
        $firstPath = $this->writeManifest('first.json', $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', []),
        ]));
        $secondPath = $this->writeManifest('second.json', $this->manifest([
            $this->module('Admin', 'Other\\AdminMethod', []),
        ]));
        config()->set('skir-server.manifests', [$firstPath, $secondPath]);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage(
            "Skir module [Admin] is declared as [App\\Skir\\AdminMethod] in manifest [{$firstPath}] and [Other\\AdminMethod] in manifest [{$secondPath}].",
        );

        app(ManifestRepository::class)->modules();
    }

    public function test_it_accepts_a_scalar_request_type_with_no_request_class(): void
    {
        $path = $this->writeManifest('manifest.json', $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', [
                $this->method('CountUsers', 'CountUsers', 'countUsers', 'int', null, 'int', null),
            ]),
        ]));
        config()->set('skir-server.manifests', [$path]);

        $method = app(ManifestRepository::class)->methods()['Admin.CountUsers'];

        self::assertSame('int', $method->requestType);
        self::assertNull($method->requestClass);
        self::assertNull($method->responseClass);
    }

    public function test_it_rejects_invalid_json(): void
    {
        $path = $this->writeManifest('manifest.json', '{');
        config()->set('skir-server.manifests', [$path]);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage("Skir manifest [{$path}] contains invalid JSON");

        app(ManifestRepository::class)->methods();
    }

    public function test_it_rejects_an_unsupported_manifest_version(): void
    {
        $path = $this->writeManifest('manifest.json', [
            'version' => 2,
            'generator' => 'php',
            'modules' => [],
        ]);
        config()->set('skir-server.manifests', [$path]);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage("Skir manifest [{$path}] uses unsupported version [2]; expected version [1].");

        app(ManifestRepository::class)->methods();
    }

    public function test_it_rejects_duplicate_method_ids_across_manifests(): void
    {
        $firstPath = $this->writeManifest('first.json', $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', [$this->method('GetUser', 'GetUser', 'getUser')]),
        ]));
        $secondPath = $this->writeManifest('second.json', $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', [$this->method('GetUser', 'GetUser', 'getUser')]),
        ]));
        config()->set('skir-server.manifests', [$firstPath, $secondPath]);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage(
            "Duplicate Skir method [Admin.GetUser] found in manifest [{$secondPath}]; first declared in manifest [{$firstPath}].",
        );

        app(ManifestRepository::class)->methods();
    }

    #[DataProvider('blankRequiredStringProvider')]
    public function test_it_rejects_blank_required_strings(string $field): void
    {
        $manifest = $this->manifest([
            $this->module('Admin', 'App\\Skir\\AdminMethod', [self::validMethod()]),
        ]);
        $this->setManifestField($manifest, $field, " \t ");
        $path = $this->writeManifest('manifest.json', $manifest);
        config()->set('skir-server.manifests', [$path]);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage("Skir manifest [{$path}] has an invalid [{$field}] field");

        app(ManifestRepository::class)->methods();
    }

    public static function blankRequiredStringProvider(): iterable
    {
        yield 'generator' => ['generator'];
        yield 'module name' => ['modules.0.name'];
        yield 'method enum' => ['modules.0.methodEnum'];
        yield 'method name' => ['modules.0.methods.0.name'];
        yield 'enum case' => ['modules.0.methods.0.enumCase'];
        yield 'PHP method' => ['modules.0.methods.0.phpMethod'];
        yield 'request type' => ['modules.0.methods.0.requestType'];
        yield 'request class' => ['modules.0.methods.0.requestClass'];
        yield 'response type' => ['modules.0.methods.0.responseType'];
        yield 'response class' => ['modules.0.methods.0.responseClass'];
    }

    #[DataProvider('invalidManifestProvider')]
    public function test_it_rejects_missing_required_keys_and_wrong_field_types(array $manifest, string $field): void
    {
        $path = $this->writeManifest('manifest.json', $manifest);
        config()->set('skir-server.manifests', [$path]);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage("Skir manifest [{$path}] has an invalid [{$field}] field");

        app(ManifestRepository::class)->methods();
    }

    public static function invalidManifestProvider(): iterable
    {
        yield 'root must be an object' => [[], 'root'];

        yield 'version is required' => [['generator' => 'php', 'modules' => []], 'version'];

        yield 'generator must be a string' => [['version' => 1, 'generator' => 1, 'modules' => []], 'generator'];

        yield 'modules must be a list' => [['version' => 1, 'generator' => 'php', 'modules' => 'Admin'], 'modules'];

        yield 'module name is required' => [[
            'version' => 1,
            'generator' => 'php',
            'modules' => [['methodEnum' => 'App\\Skir\\AdminMethod', 'methods' => []]],
        ], 'modules.0.name'];

        yield 'method enum must be a string' => [[
            'version' => 1,
            'generator' => 'php',
            'modules' => [['name' => 'Admin', 'methodEnum' => null, 'methods' => []]],
        ], 'modules.0.methodEnum'];

        yield 'methods must be a list' => [[
            'version' => 1,
            'generator' => 'php',
            'modules' => [['name' => 'Admin', 'methodEnum' => 'App\\Skir\\AdminMethod', 'methods' => ['GetUser' => []]]],
        ], 'modules.0.methods'];

        foreach (['name', 'enumCase', 'phpMethod', 'requestType', 'responseType'] as $field) {
            $method = self::validMethod();
            $method[$field] = null;

            yield "method {$field} must be a string" => [[
                'version' => 1,
                'generator' => 'php',
                'modules' => [[
                    'name' => 'Admin',
                    'methodEnum' => 'App\\Skir\\AdminMethod',
                    'methods' => [$method],
                ]],
            ], "modules.0.methods.0.{$field}"];
        }

        $method = self::validMethod();
        $method['requestClass'] = 1;

        yield 'request class must be nullable string' => [[
            'version' => 1,
            'generator' => 'php',
            'modules' => [[
                'name' => 'Admin',
                'methodEnum' => 'App\\Skir\\AdminMethod',
                'methods' => [$method],
            ]],
        ], 'modules.0.methods.0.requestClass'];

        $method = self::validMethod();
        unset($method['responseClass']);

        yield 'response class is required' => [[
            'version' => 1,
            'generator' => 'php',
            'modules' => [[
                'name' => 'Admin',
                'methodEnum' => 'App\\Skir\\AdminMethod',
                'methods' => [$method],
            ]],
        ], 'modules.0.methods.0.responseClass'];
    }

    public function test_it_rejects_a_missing_manifest_with_an_actionable_message(): void
    {
        $path = $this->temporaryDirectory.'/missing.json';
        config()->set('skir-server.manifests', [$path]);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage("Skir manifest [{$path}] does not exist or is not readable. Run [npx skir gen] to generate it.");

        app(ManifestRepository::class)->methods();
    }

    public function test_it_rejects_a_directory_manifest_path(): void
    {
        config()->set('skir-server.manifests', [$this->temporaryDirectory]);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage(
            "Skir manifest [{$this->temporaryDirectory}] does not exist or is not readable. Run [npx skir gen] to generate it.",
        );

        app(ManifestRepository::class)->methods();
    }

    public function test_it_rejects_a_blank_configured_manifest_path(): void
    {
        config()->set('skir-server.manifests', ['  ']);

        $this->expectException(SkirScaffoldingException::class);
        $this->expectExceptionMessage('Skir manifest configuration has an invalid [manifests.0] field; expected a non-blank file path.');

        app(ManifestRepository::class)->methods();
    }

    public function test_the_service_provider_registers_manifest_configuration_and_repository(): void
    {
        self::assertSame([base_path('app/Skir/skirout/skir-server-manifest.json')], config('skir-server.manifests'));
        self::assertSame(['npx', 'skir', 'gen'], config('skir-server.generator_command'));
        self::assertSame('module', config('skir-server.scaffolding.controller_style'));
        self::assertSame('App\\Skir', config('skir-server.scaffolding.controller_namespace'));
        self::assertSame('App\\Http\\Requests\\Skir', config('skir-server.scaffolding.request_namespace'));
        self::assertTrue(config('skir-server.scaffolding.form_requests'));
        self::assertSame(app(ManifestRepository::class), app(ManifestRepository::class));

        $publishedPaths = ServiceProvider::pathsToPublish(SkirServerServiceProvider::class, 'skir-server-config');

        self::assertContains(config_path('skir-server.php'), $publishedPaths);
    }

    public function test_the_service_provider_preserves_scaffolding_defaults_with_a_partial_override(): void
    {
        config()->set('skir-server', [
            'manifests' => ['/custom/manifest.json'],
            'scaffolding' => [
                'controller_style' => 'method',
            ],
        ]);

        (new SkirServerServiceProvider($this->app))->register();

        self::assertSame(['/custom/manifest.json'], config('skir-server.manifests'));
        self::assertSame('method', config('skir-server.scaffolding.controller_style'));
        self::assertSame('App\\Skir', config('skir-server.scaffolding.controller_namespace'));
        self::assertSame('App\\Http\\Requests\\Skir', config('skir-server.scaffolding.request_namespace'));
        self::assertTrue(config('skir-server.scaffolding.form_requests'));
        self::assertSame(['npx', 'skir', 'gen'], config('skir-server.generator_command'));
    }

    /** @param array<int, array<string, mixed>> $modules */
    private function manifest(array $modules): array
    {
        return [
            'version' => 1,
            'generator' => 'php',
            'modules' => $modules,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $methods
     * @return array<string, mixed>
     */
    private function module(string $name, string $methodEnum, array $methods): array
    {
        return [
            'name' => $name,
            'methodEnum' => $methodEnum,
            'methods' => $methods,
        ];
    }

    /** @return array<string, mixed> */
    private function method(
        string $name,
        string $enumCase,
        string $phpMethod,
        string $requestType = 'void',
        ?string $requestClass = null,
        string $responseType = 'void',
        ?string $responseClass = null,
    ): array {
        return [
            'name' => $name,
            'enumCase' => $enumCase,
            'phpMethod' => $phpMethod,
            'requestType' => $requestType,
            'requestClass' => $requestClass,
            'responseType' => $responseType,
            'responseClass' => $responseClass,
        ];
    }

    /** @return array<string, mixed> */
    private static function validMethod(): array
    {
        return [
            'name' => 'GetUser',
            'enumCase' => 'GetUser',
            'phpMethod' => 'getUser',
            'requestType' => 'void',
            'requestClass' => null,
            'responseType' => 'void',
            'responseClass' => null,
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function setManifestField(array &$manifest, string $field, mixed $value): void
    {
        $target = &$manifest;

        foreach (explode('.', $field) as $segment) {
            $target = &$target[$segment];
        }

        $target = $value;
    }

    private function writeManifest(string $name, array|string $contents): string
    {
        $path = $this->temporaryDirectory.'/'.$name;
        $contents = is_array($contents)
            ? json_encode($contents, JSON_THROW_ON_ERROR)
            : $contents;
        file_put_contents($path, $contents);

        return $path;
    }
}
