<?php

declare(strict_types=1);

namespace Skir\Server\Exceptions;

use JsonException;
use RuntimeException;

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
}
