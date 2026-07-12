<?php

declare(strict_types=1);

namespace Skir\Server\Exceptions;

use JsonException;
use RuntimeException;
use Throwable;

final class SkirScaffoldingException extends RuntimeException
{
    public static function unreadableManifest(string $path, string $generatorCommand): self
    {
        return new self(
            "Skir manifest [{$path}] does not exist or is not readable. Run [{$generatorCommand}] to generate it.",
        );
    }

    public static function invalidJson(string $path, JsonException $exception): self
    {
        return new self(
            "Skir manifest [{$path}] contains invalid JSON: {$exception->getMessage()}",
            previous: $exception,
        );
    }

    public static function unsupportedVersion(string $path, mixed $version): self
    {
        $displayVersion = is_scalar($version) ? (string) $version : get_debug_type($version);

        return new self("Skir manifest [{$path}] uses unsupported version [{$displayVersion}]; expected version [1].");
    }

    public static function invalidField(string $path, string $field, string $expected): self
    {
        return new self("Skir manifest [{$path}] has an invalid [{$field}] field; expected {$expected}.");
    }

    public static function invalidConfigurationField(string $field, string $expected): self
    {
        return new self("Skir manifest configuration has an invalid [{$field}] field; expected {$expected}.");
    }

    public static function duplicateMethod(string $methodId, string $originalPath, string $duplicatePath): self
    {
        return new self(
            "Duplicate Skir method [{$methodId}] found in manifest [{$duplicatePath}]; first declared in manifest [{$originalPath}].",
        );
    }

    public static function conflictingModule(
        string $module,
        string $originalEnumClass,
        string $originalPath,
        string $duplicateEnumClass,
        string $duplicatePath,
    ): self {
        return new self(
            "Skir module [{$module}] is declared as [{$originalEnumClass}] in manifest [{$originalPath}] and [{$duplicateEnumClass}] in manifest [{$duplicatePath}].",
        );
    }

    public static function missingObjectRequestClass(string $methodId): self
    {
        return new self(
            "Skir method [{$methodId}] does not have an object request class and cannot use a form request.",
        );
    }

    public static function unknownMethod(string $methodId): self
    {
        return new self("Unknown Skir method [{$methodId}]. Check the generated Skir manifest for an exact method ID.");
    }

    public static function missingMethod(): self
    {
        return new self('A Skir method is required when the command is not interactive. Pass the exact method ID.');
    }

    public static function noObjectRequestMethods(): self
    {
        return new self('No Skir methods with object request classes are available in the configured manifests.');
    }

    public static function invalidClassName(string $className): self
    {
        return new self("The form request name [{$className}] must be a valid PHP class name without a path or namespace.");
    }

    public static function invalidRequestNamespace(mixed $namespace): self
    {
        $displayNamespace = is_scalar($namespace) ? (string) $namespace : get_debug_type($namespace);

        return new self(
            "Skir scaffolding configuration [request_namespace] contains invalid namespace [{$displayNamespace}]. Use canonical PHP namespace segments.",
        );
    }

    public static function invalidIdentifier(string $field, string $value): self
    {
        return new self("Skir method [{$field}] contains invalid PHP identifier [{$value}]. Regenerate or fix the manifest.");
    }

    public static function existingFile(string $path): self
    {
        return new self("Form request [{$path}] already exists and was not modified.");
    }

    public static function unreadableStub(string $path): self
    {
        return new self("Skir form request stub [{$path}] does not exist or is not readable.");
    }

    public static function invalidRenderedSource(string $path, Throwable $exception): self
    {
        return new self("Rendered form request for [{$path}] is invalid PHP: {$exception->getMessage()}", previous: $exception);
    }

    public static function unableToCreateDirectory(string $path): self
    {
        return new self("Unable to create the form request directory [{$path}].");
    }

    public static function unableToWriteFile(string $path): self
    {
        return new self("Unable to atomically write the form request [{$path}].");
    }
}
