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

    public static function duplicateControllerSelectionIdentity(
        string $firstMethodId,
        string $secondMethodId,
        string $identity,
    ): self {
        return new self(
            "Skir methods [{$firstMethodId}] and [{$secondMethodId}] share Skir identity [{$identity}].",
        );
    }

    public static function unsupportedControllerStyle(string $style): self
    {
        return new self("Controller style [{$style}] is not supported.");
    }

    public static function existingController(string $path): self
    {
        return new self("Skir controller [{$path}] already exists and was not modified.");
    }

    public static function invalidExistingController(string $path, Throwable $exception): self
    {
        return new self("Existing Skir controller [{$path}] is invalid PHP: {$exception->getMessage()}", previous: $exception);
    }

    public static function missingControllerClass(string $path, string $class): self
    {
        return new self("Existing Skir controller [{$path}] does not declare expected class [{$class}].");
    }

    public static function occupiedControllerMethod(
        string $path,
        string $method,
        string $identity,
    ): self {
        return new self(
            "Skir controller [{$path}] PHP method [{$method}] already exists without Skir identity [{$identity}].",
        );
    }

    public static function duplicateControllerIdentity(string $path, string $identity): self
    {
        return new self("Skir controller [{$path}] declares Skir identity [{$identity}] more than once.");
    }

    public static function controllerChangedDuringScaffolding(string $path): self
    {
        return new self("Skir controller [{$path}] changed during scaffolding and was not modified.");
    }

    public static function transactionRollbackFailed(Throwable $mutation, Throwable $rollback): self
    {
        return new self(
            "Skir scaffolding failed and could not be rolled back safely. Mutation error: {$mutation->getMessage()} Rollback error: {$rollback->getMessage()}",
            previous: $rollback,
        );
    }

    public static function rollbackArtifactChanged(string $path): self
    {
        return new self("Scaffolding artifact [{$path}] changed after publication and could not be rolled back safely.");
    }

    public static function displacedControllerPreserved(
        string $destinationPath,
        string $backupPath,
    ): self {
        return new self(
            "Skir controller [{$destinationPath}] could not be restored without clobbering another write. Its displaced original is preserved at [{$backupPath}].",
        );
    }

    public static function temporaryCleanupFailedAfterRollback(
        string $destinationPath,
        string $temporaryPath,
        Throwable $cleanup,
    ): self {
        return new self(
            "Scaffolding mutation was rolled back successfully for [{$destinationPath}], but temporary file [{$temporaryPath}] could not be removed: {$cleanup->getMessage()}",
            previous: $cleanup,
        );
    }

    public static function failureWithTemporaryCleanup(
        Throwable $primary,
        string $temporaryPath,
        Throwable $cleanup,
    ): self {
        return new self(
            "{$primary->getMessage()} Additionally, temporary file [{$temporaryPath}] could not be removed: {$cleanup->getMessage()}",
            previous: $primary,
        );
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

    public static function plannedOutputCollision(string $firstPath, string $secondPath): self
    {
        return new self(
            "Scaffolding outputs [{$firstPath}] and [{$secondPath}] resolve to the same planned destination.",
        );
    }

    public static function plannedOutputAppeared(string $path): self
    {
        return new self("Scaffolding output [{$path}] appeared during scaffolding and was not modified.");
    }

    public static function renderedControllerDoesNotCompile(string $path, string $output): self
    {
        return new self("Rendered Skir controller for [{$path}] does not compile: {$output}");
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

    public static function controllerAtomicPublicationUnavailable(string $path): self
    {
        return new self(
            "Atomic publication is unavailable for Skir controller [{$path}]. Ensure the application filesystem supports same-directory hard links.",
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
