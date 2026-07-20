<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding\Manifest;

use JsonException;
use Skir\Server\Exceptions\SkirScaffoldingException;
use stdClass;

final class ManifestRepository
{
    /** @var array<string, SkirMethodDefinition>|null */
    private ?array $methods = null;

    /** @var list<SkirModuleDefinition>|null */
    private ?array $modules = null;

    /** @return array<string, SkirMethodDefinition> */
    public function methods(): array
    {
        $this->load();

        return $this->methods ?? [];
    }

    /** @return list<SkirModuleDefinition> */
    public function modules(): array
    {
        $this->load();

        return $this->modules ?? [];
    }

    public function reload(): void
    {
        $this->methods = null;
        $this->modules = null;
    }

    private function load(): void
    {
        if ($this->methods !== null) {
            return;
        }

        $methods = [];
        $modules = [];
        $methodOrigins = [];
        $moduleIndexes = [];
        $moduleOrigins = [];

        foreach ($this->manifestPaths() as $path) {
            foreach ($this->readModules($path) as $module) {
                $moduleIndex = $moduleIndexes[$module->name] ?? null;
                $moduleMethods = [];

                if ($moduleIndex !== null) {
                    $existingModule = $modules[$moduleIndex];

                    if ($existingModule->enumClass !== $module->enumClass) {
                        throw SkirScaffoldingException::conflictingModule(
                            module: $module->name,
                            originalEnumClass: $existingModule->enumClass,
                            originalPath: $moduleOrigins[$module->name],
                            duplicateEnumClass: $module->enumClass,
                            duplicatePath: $path,
                        );
                    }

                    $moduleMethods = $existingModule->methods;
                }

                foreach ($module->methods as $method) {
                    if (array_key_exists($method->id(), $methods)) {
                        throw SkirScaffoldingException::duplicateMethod(
                            methodId: $method->id(),
                            originalPath: $methodOrigins[$method->id()],
                            duplicatePath: $path,
                        );
                    }

                    $methods[$method->id()] = $method;
                    $methodOrigins[$method->id()] = $path;
                    $moduleMethods[] = $method;
                }

                $mergedModule = new SkirModuleDefinition($module->name, $module->enumClass, $moduleMethods);

                if ($moduleIndex !== null) {
                    $modules[$moduleIndex] = $mergedModule;

                    continue;
                }

                $moduleIndexes[$module->name] = count($modules);
                $moduleOrigins[$module->name] = $path;
                $modules[] = $mergedModule;
            }
        }

        $this->methods = $methods;
        $this->modules = $modules;
    }

    /** @return list<string> */
    private function manifestPaths(): array
    {
        $paths = config('skir-server.manifests');

        if (! is_array($paths)) {
            throw SkirScaffoldingException::invalidField('configuration', 'manifests', 'a list of file paths');
        }

        if (! array_is_list($paths)) {
            throw SkirScaffoldingException::invalidField('configuration', 'manifests', 'a list of file paths');
        }

        foreach ($paths as $index => $path) {
            if (! is_string($path)) {
                throw SkirScaffoldingException::invalidConfigurationField("manifests.{$index}", 'a non-blank file path');
            }

            if (trim($path) === '') {
                throw SkirScaffoldingException::invalidConfigurationField("manifests.{$index}", 'a non-blank file path');
            }
        }

        return $paths;
    }

    /** @return list<SkirModuleDefinition> */
    private function readModules(string $path): array
    {
        if (! is_file($path)) {
            throw SkirScaffoldingException::unreadableManifest($path, $this->generatorCommand());
        }

        if (! is_readable($path)) {
            throw SkirScaffoldingException::unreadableManifest($path, $this->generatorCommand());
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw SkirScaffoldingException::unreadableManifest($path, $this->generatorCommand());
        }

        try {
            $manifest = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw SkirScaffoldingException::invalidJson($path, $exception);
        }

        if (! $manifest instanceof stdClass) {
            throw SkirScaffoldingException::invalidField($path, 'root', 'an object');
        }

        $version = $manifest->version ?? null;

        if (! is_int($version)) {
            throw SkirScaffoldingException::invalidField($path, 'version', 'the integer 1');
        }

        if ($version !== 1) {
            throw SkirScaffoldingException::unsupportedVersion($path, $version);
        }

        $this->requiredString($manifest, 'generator', $path, 'generator');
        $manifestModules = $this->requiredList($manifest, 'modules', $path, 'modules');
        $modules = [];

        foreach ($manifestModules as $moduleIndex => $manifestModule) {
            $field = "modules.{$moduleIndex}";

            if (! $manifestModule instanceof stdClass) {
                throw SkirScaffoldingException::invalidField($path, $field, 'an object');
            }

            $moduleName = $this->requiredString($manifestModule, 'name', $path, "{$field}.name");
            $enumClass = $this->requiredString($manifestModule, 'methodEnum', $path, "{$field}.methodEnum");
            $manifestMethods = $this->requiredList($manifestModule, 'methods', $path, "{$field}.methods");
            $methods = [];

            foreach ($manifestMethods as $methodIndex => $manifestMethod) {
                $methodField = "{$field}.methods.{$methodIndex}";

                if (! $manifestMethod instanceof stdClass) {
                    throw SkirScaffoldingException::invalidField($path, $methodField, 'an object');
                }

                $methods[] = new SkirMethodDefinition(
                    module: $moduleName,
                    name: $this->requiredString($manifestMethod, 'name', $path, "{$methodField}.name"),
                    enumClass: $enumClass,
                    enumCase: $this->requiredString($manifestMethod, 'enumCase', $path, "{$methodField}.enumCase"),
                    phpMethod: $this->requiredString($manifestMethod, 'phpMethod', $path, "{$methodField}.phpMethod"),
                    requestType: $this->requiredString($manifestMethod, 'requestType', $path, "{$methodField}.requestType"),
                    requestClass: $this->requiredNullableString($manifestMethod, 'requestClass', $path, "{$methodField}.requestClass"),
                    responseType: $this->requiredString($manifestMethod, 'responseType', $path, "{$methodField}.responseType"),
                    responseClass: $this->requiredNullableString($manifestMethod, 'responseClass', $path, "{$methodField}.responseClass"),
                );
            }

            $modules[] = new SkirModuleDefinition($moduleName, $enumClass, $methods);
        }

        return $modules;
    }

    private function requiredString(stdClass $object, string $key, string $path, string $field): string
    {
        if (! property_exists($object, $key)) {
            throw SkirScaffoldingException::invalidField($path, $field, 'a string');
        }

        $value = $object->{$key};

        if (! is_string($value)) {
            throw SkirScaffoldingException::invalidField($path, $field, 'a string');
        }

        if (trim($value) === '') {
            throw SkirScaffoldingException::invalidField($path, $field, 'a non-blank string');
        }

        return $value;
    }

    /** @return list<mixed> */
    private function requiredList(stdClass $object, string $key, string $path, string $field): array
    {
        if (! property_exists($object, $key)) {
            throw SkirScaffoldingException::invalidField($path, $field, 'a list');
        }

        $value = $object->{$key};

        if (! is_array($value)) {
            throw SkirScaffoldingException::invalidField($path, $field, 'a list');
        }

        return $value;
    }

    private function requiredNullableString(stdClass $object, string $key, string $path, string $field): ?string
    {
        if (! property_exists($object, $key)) {
            throw SkirScaffoldingException::invalidField($path, $field, 'a string or null');
        }

        $value = $object->{$key};

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw SkirScaffoldingException::invalidField($path, $field, 'a string or null');
        }

        if (trim($value) === '') {
            throw SkirScaffoldingException::invalidField($path, $field, 'a non-blank string or null');
        }

        return $value;
    }

    private function generatorCommand(): string
    {
        $command = config('skir-server.generator_command', ['npx', 'skir', 'gen']);

        if (! is_array($command)) {
            return 'npx skir gen';
        }

        return implode(' ', array_filter($command, is_string(...)));
    }
}
