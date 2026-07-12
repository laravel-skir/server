<?php

declare(strict_types=1);

namespace Skir\Server\Exceptions;

use JsonException;
use RuntimeException;
use Throwable;

final class SkirScaffoldingException extends RuntimeException
{
    public static function emptyControllerSelection(): self
    {
        return new self('Controller scaffolding requires at least one Skir method.');
    }

    public static function invalidSingleControllerCombination(): self
    {
        return new self('A single controller class may only be supplied for the [single] controller style.');
    }

    public static function invalidControllerSelectionMethod(): self
    {
        return new self('Controller scaffolding selections may only contain Skir method definitions.');
    }

    public static function invalidControllerSelectionMethodList(): self
    {
        return new self('Controller scaffolding requires a list of Skir method definitions.');
    }

    public static function duplicateControllerSelectionMethod(string $methodId): self
    {
        return new self("Skir method [{$methodId}] was selected more than once for controller scaffolding.");
    }

    public static function unsupportedControllerStyle(string $style): self
    {
        return new self("Controller style [{$style}] is not supported.");
    }

    public static function existingController(string $path): self
    {
        return new self("Skir controller [{$path}] already exists and was not modified.");
    }

    public static function invalidControllerNamespace(mixed $namespace): self
    {
        $displayNamespace = is_scalar($namespace) ? (string) $namespace : get_debug_type($namespace);

        return new self("Skir scaffolding configuration [controller_namespace] contains invalid namespace [{$displayNamespace}].");
    }

    public static function invalidRenderedController(string $path, Throwable $exception): self
    {
        return new self("Rendered Skir controller for [{$path}] is invalid PHP: {$exception->getMessage()}", previous: $exception);
    }

    public static function conflictingControllerMethod(string $method, string $controller): self
    {
        return new self("Skir controller [{$controller}] has conflicting PHP method [{$method}].");
    }

    public static function controllerNamespaceOutsideApplication(string $namespace, string $applicationNamespace): self
    {
        return new self(
            "Skir scaffolding controller namespace [{$namespace}] must start with application namespace [{$applicationNamespace}].",
        );
    }

    public static function invalidSingleController(string $controller): self
    {
        return new self("Single Skir controller [{$controller}] must be a canonical class under the application namespace.");
    }

    public static function cleanupFailedAfterControllerPublication(
        string $destinationPath,
        string $temporaryPath,
        Throwable $exception,
    ): self {
        return new self(
            "Skir controller [{$destinationPath}] was created, but temporary file cleanup failed; remove leftover [{$temporaryPath}] manually: {$exception->getMessage()}",
            previous: $exception,
        );
    }

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

    public static function invalidRenderedSource(string $path, Throwable $exception): self
    {
        return new self("Rendered form request for [{$path}] is invalid PHP: {$exception->getMessage()}", previous: $exception);
    }

    public static function atomicPublicationUnavailable(string $path): self
    {
        return new self(
            "Atomic publication is unavailable for form request [{$path}]. Ensure the application filesystem supports same-directory hard links.",
        );
    }

    public static function importCollision(string $shortName): self
    {
        return new self("Imported short name [{$shortName}] is declared more than once in the generated form request.");
    }

    public static function namespaceOutsideApplication(string $namespace, string $applicationNamespace): self
    {
        return new self(
            "Skir scaffolding configuration [request_namespace] [{$namespace}] must start with application namespace [{$applicationNamespace}].",
        );
    }

    public static function filesystemOperationFailed(
        string $operation,
        string $path,
        ?Throwable $exception = null,
    ): self {
        $details = $exception === null ? '' : ": {$exception->getMessage()}";

        return new self(
            "Filesystem operation [{$operation}] failed for [{$path}]{$details}.",
            previous: $exception,
        );
    }

    public static function cleanupFailedAfterPublication(
        string $destinationPath,
        string $temporaryPath,
        Throwable $exception,
    ): self {
        return new self(
            "Form request [{$destinationPath}] was created, but temporary file cleanup failed; remove leftover [{$temporaryPath}] manually: {$exception->getMessage()}",
            previous: $exception,
        );
    }
}
