<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use RuntimeException;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Throwable;

final class ControllerScaffolder
{
    private readonly ControllerMethodRenderer $methodRenderer;

    private readonly PhpControllerEditor $controllerEditor;

    public function __construct(
        private readonly AtomicFilePublisher $publisher,
        private readonly ScaffoldingFilesystem $filesystem,
        private readonly FormRequestScaffolder $formRequestScaffolder,
        private readonly PhpSourceValidator $sourceValidator,
        ?ControllerMethodRenderer $methodRenderer = null,
        ?PhpControllerEditor $controllerEditor = null,
    ) {
        $this->methodRenderer = $methodRenderer ?? new ControllerMethodRenderer;
        $this->controllerEditor = $controllerEditor ?? new PhpControllerEditor(
            $this->methodRenderer,
            $sourceValidator,
        );
    }

    public function scaffold(ScaffoldingSelection $selection): ControllerScaffoldingResult
    {
        $renderedRequests = $this->renderRequests($selection);
        $requestClasses = $this->requestClasses($selection);
        $renderedControllers = $this->renderControllers($selection, $requestClasses);
        $this->preflightPlannedDestinations($renderedRequests, $renderedControllers);
        $unchangedPaths = [];
        $requestsToCreate = [];
        $controllersToCreate = [];
        $controllersToUpdate = [];
        $unchangedSnapshots = [];

        foreach ($renderedRequests as $methodId => $renderedRequest) {
            if ($this->filesystem->exists($renderedRequest->destinationPath)) {
                $snapshot = $this->filesystem->snapshot($renderedRequest->destinationPath);

                if ($snapshot === null) {
                    throw SkirScaffoldingException::controllerChangedDuringScaffolding(
                        $renderedRequest->destinationPath,
                    );
                }

                $unchangedPaths[] = $renderedRequest->destinationPath;
                $unchangedSnapshots[$renderedRequest->destinationPath] = $snapshot;

                continue;
            }

            $requestsToCreate[$methodId] = $renderedRequest;
        }

        foreach ($renderedControllers as $renderedController) {
            if (! $renderedController->existing) {
                if ($this->filesystem->exists($renderedController->file->destinationPath)) {
                    throw SkirScaffoldingException::existingController($renderedController->file->destinationPath);
                }

                $controllersToCreate[] = $renderedController;

                continue;
            }

            if (! $renderedController->changed) {
                $unchangedPaths[] = $renderedController->file->destinationPath;
                $unchangedSnapshots[$renderedController->file->destinationPath] = $renderedController->originalSnapshot;

                continue;
            }

            $controllersToUpdate[] = $renderedController;
        }

        $updatedPaths = [];
        $createdPaths = [];
        $rollbackActions = [];
        $replacementBackups = [];

        $this->revalidateSnapshots($unchangedSnapshots);

        foreach ($controllersToUpdate as $renderedController) {
            $this->revalidateSnapshot(
                $renderedController->file->destinationPath,
                $renderedController->originalSnapshot,
            );
        }

        foreach ($requestsToCreate as $renderedRequest) {
            $this->revalidateMissing($renderedRequest->destinationPath);
        }

        foreach ($controllersToCreate as $renderedController) {
            $this->revalidateMissing($renderedController->file->destinationPath);
        }

        try {
            foreach ($controllersToUpdate as $renderedController) {
                $replacement = $this->replace($renderedController);
                $rollbackActions[] = fn () => $this->rollbackReplacement($replacement);
                $replacementBackups[] = $replacement->backupPath;
                $updatedPaths[] = $renderedController->file->destinationPath;
            }

            foreach ($requestsToCreate as $renderedRequest) {
                $missingDirectories = $this->missingDirectories($renderedRequest->destinationPath);

                try {
                    $publishedSnapshot = $this->publishCreated($renderedRequest, true);
                } catch (Throwable $exception) {
                    $this->removeEmptyDirectories($missingDirectories);

                    throw $exception;
                }

                $createdPath = $renderedRequest->destinationPath;
                $rollbackActions[] = fn () => $this->rollbackCreated(
                    $createdPath,
                    $publishedSnapshot,
                    $missingDirectories,
                );
                $createdPaths[] = $createdPath;
            }

            foreach ($controllersToCreate as $renderedController) {
                $missingDirectories = $this->missingDirectories($renderedController->file->destinationPath);

                try {
                    $publishedSnapshot = $this->publishCreated($renderedController->file, false);
                } catch (Throwable $exception) {
                    $this->removeEmptyDirectories($missingDirectories);

                    throw $exception;
                }

                $rollbackActions[] = fn () => $this->rollbackCreated(
                    $renderedController->file->destinationPath,
                    $publishedSnapshot,
                    $missingDirectories,
                );
                $createdPaths[] = $renderedController->file->destinationPath;
            }

            $this->revalidateSnapshots($unchangedSnapshots);
        } catch (Throwable $exception) {
            $rollbackFailure = null;

            foreach (array_reverse($rollbackActions) as $rollbackAction) {
                try {
                    $rollbackAction();
                } catch (Throwable $rollbackException) {
                    $rollbackFailure ??= $rollbackException;
                }
            }

            if ($rollbackFailure !== null) {
                throw SkirScaffoldingException::transactionRollbackFailed($exception, $rollbackFailure);
            }

            throw $exception;
        }

        $cleanupWarnings = [];

        foreach ($replacementBackups as $backupPath) {
            try {
                $this->filesystem->remove($backupPath);
            } catch (Throwable $exception) {
                $cleanupWarnings[] = "Controller changes were committed, but displaced backup [{$backupPath}] could not be removed: {$exception->getMessage()}";
            }
        }

        $warnings = array_merge(...array_map(
            static fn (RenderedController $controller): array => $controller->warnings,
            $renderedControllers,
        ));

        return new ControllerScaffoldingResult(
            $createdPaths,
            $unchangedPaths,
            array_merge(...array_map(
                static fn (RenderedController $controller): array => $controller->registrations,
                $renderedControllers,
            )),
            $updatedPaths,
            array_merge($warnings, $cleanupWarnings),
        );
    }

    /**
     * @param  array<string, RenderedFile>  $renderedRequests
     * @param  list<RenderedController>  $renderedControllers
     */
    private function preflightPlannedDestinations(
        array $renderedRequests,
        array $renderedControllers,
    ): void {
        $plannedPaths = [];

        foreach ($renderedRequests as $renderedRequest) {
            $plannedPaths[] = $renderedRequest->destinationPath;
        }

        foreach ($renderedControllers as $renderedController) {
            $plannedPaths[] = $renderedController->file->destinationPath;
        }

        $destinations = [];

        foreach ($plannedPaths as $plannedPath) {
            $normalizedPath = strtolower(str_replace('\\', '/', $plannedPath));

            if (isset($destinations[$normalizedPath])) {
                throw SkirScaffoldingException::plannedOutputCollision(
                    $destinations[$normalizedPath],
                    $plannedPath,
                );
            }

            $destinations[$normalizedPath] = $plannedPath;
        }
    }

    /** @return array<string, RenderedFile> */
    private function renderRequests(ScaffoldingSelection $selection): array
    {
        if (! $selection->withFormRequests) {
            return [];
        }

        $renderedRequests = [];

        foreach ($selection->methods as $method) {
            $renderedRequests[$method->id()] = $this->formRequestScaffolder->render($method);
        }

        return $renderedRequests;
    }

    /** @return array<string, string> */
    private function requestClasses(ScaffoldingSelection $selection): array
    {
        if (! $selection->withFormRequests) {
            return [];
        }

        $configuredNamespace = config('skir-server.scaffolding.request_namespace');
        $requestNamespace = is_string($configuredNamespace) ? trim($configuredNamespace, '\\') : '';
        $requestClasses = [];

        foreach ($selection->methods as $method) {
            $moduleNamespace = str_replace('.', '\\', $method->module);
            $requestClasses[$method->id()] = "{$requestNamespace}\\{$moduleNamespace}\\{$method->name}FormRequest";
        }

        return $requestClasses;
    }

    /**
     * @param  array<string, string>  $requestClasses
     * @return list<RenderedController>
     */
    private function renderControllers(ScaffoldingSelection $selection, array $requestClasses): array
    {
        $this->validateMethods($selection->methods);

        return match ($selection->style) {
            ControllerStyle::Module => $this->renderModuleControllers($selection->methods, $requestClasses),
            ControllerStyle::Invokable => $this->renderInvokableControllers($selection->methods, $requestClasses),
            ControllerStyle::Single => [$this->renderSingleController($selection, $requestClasses)],
        };
    }

    /**
     * @param  list<SkirMethodDefinition>  $methods
     * @param  array<string, string>  $requestClasses
     * @return list<RenderedController>
     */
    private function renderModuleControllers(array $methods, array $requestClasses): array
    {
        $methodsByModule = [];

        foreach ($methods as $method) {
            $methodsByModule[$method->module][] = $method;
        }

        $controllers = [];

        foreach ($methodsByModule as $module => $moduleMethods) {
            $moduleSegments = explode('.', $module);
            $className = end($moduleSegments).'Controller';
            $namespace = $this->controllerNamespace().'\\'.implode('\\', $moduleSegments);
            $controllerClass = "{$namespace}\\{$className}";
            $controllers[] = $this->renderController(
                $namespace,
                $className,
                $moduleMethods,
                $requestClasses,
                false,
                [new RouteRegistration($controllerClass)],
            );
        }

        return $controllers;
    }

    /**
     * @param  list<SkirMethodDefinition>  $methods
     * @param  array<string, string>  $requestClasses
     * @return list<RenderedController>
     */
    private function renderInvokableControllers(array $methods, array $requestClasses): array
    {
        $controllers = [];

        foreach ($methods as $method) {
            $className = "{$method->name}Controller";
            $namespace = $this->controllerNamespace().'\\'.str_replace('.', '\\', $method->module);
            $controllerClass = "{$namespace}\\{$className}";
            $controllers[] = $this->renderController(
                $namespace,
                $className,
                [$method],
                $requestClasses,
                true,
                [new RouteRegistration($controllerClass, $method->enumClass, $method->enumCase)],
            );
        }

        return $controllers;
    }

    /** @param array<string, string> $requestClasses */
    private function renderSingleController(
        ScaffoldingSelection $selection,
        array $requestClasses,
    ): RenderedController {
        $controllerClass = $this->singleControllerClass($selection->singleController);
        $segments = explode('\\', $controllerClass);
        $className = array_pop($segments);

        return $this->renderController(
            implode('\\', $segments),
            $className,
            $selection->methods,
            $requestClasses,
            false,
            [new RouteRegistration($controllerClass)],
        );
    }

    /**
     * @param  list<SkirMethodDefinition>  $methods
     * @param  array<string, string>  $requestClasses
     * @param  list<RouteRegistration>  $registrations
     */
    private function renderController(
        string $namespace,
        string $className,
        array $methods,
        array $requestClasses,
        bool $invokable,
        array $registrations,
    ): RenderedController {
        $this->validateControllerMethods($className, $methods, $invokable);
        $imports = new PhpImportMap($className);
        $this->methodRenderer->addImports($imports, $methods, $requestClasses);

        $methodSource = [];

        foreach ($methods as $method) {
            $methodSource[] = $this->methodRenderer->render(
                $method,
                $imports,
                $requestClasses[$method->id()] ?? null,
                $invokable,
            );
        }

        $stubName = $invokable ? 'skir-invokable-controller.stub' : 'skir-controller.stub';
        $source = $this->renderStub($stubName, [
            '{{ namespace }}' => $namespace,
            '{{ imports }}' => $imports->source(),
            '{{ className }}' => $className,
            '{{ methods }}' => implode("\n\n", $methodSource),
        ]);
        $destinationPath = $this->destinationPath($namespace, $className);

        $this->sourceValidator->validate($source, $destinationPath);

        if ($this->filesystem->exists($destinationPath)) {
            $originalSnapshot = $this->filesystem->snapshot($destinationPath);

            if ($originalSnapshot === null) {
                throw SkirScaffoldingException::controllerChangedDuringScaffolding($destinationPath);
            }

            $originalSource = $originalSnapshot->contents;
            $edited = $this->controllerEditor->edit(
                $originalSource,
                $destinationPath,
                $namespace,
                $className,
                $methods,
                $requestClasses,
                $invokable,
            );

            return new RenderedController(
                new RenderedFile($destinationPath, $edited->source),
                $className,
                $methods,
                $registrations,
                true,
                $edited->changed,
                $originalSource,
                $edited->warnings,
                $originalSnapshot,
            );
        }

        return new RenderedController(
            new RenderedFile($destinationPath, $source),
            $className,
            $methods,
            $registrations,
        );
    }

    /** @param list<SkirMethodDefinition> $methods */
    private function validateMethods(array $methods): void
    {
        foreach ($methods as $method) {
            foreach (explode('.', $method->module) as $moduleSegment) {
                $this->validateIdentifier($moduleSegment, 'module', $method->module);
            }

            $this->validateIdentifier($method->name, 'name', $method->name);
            $this->validateIdentifier($method->enumCase, 'enumCase', $method->enumCase);
            $this->validateIdentifier($method->phpMethod, 'phpMethod', $method->phpMethod);
            $this->validateClassReference($method->enumClass, 'enumClass');

            if ($method->requestClass !== null) {
                $this->validateClassReference($method->requestClass, 'requestClass');
            }

            if ($method->responseClass !== null) {
                $this->validateClassReference($method->responseClass, 'responseClass');
            }
        }
    }

    /** @param list<SkirMethodDefinition> $methods */
    private function validateControllerMethods(string $className, array $methods, bool $invokable): void
    {
        if ($invokable) {
            return;
        }

        $seenMethods = [];

        foreach ($methods as $method) {
            $normalizedMethod = strtolower($method->phpMethod);

            if (isset($seenMethods[$normalizedMethod])) {
                throw SkirScaffoldingException::conflictingControllerMethod($method->phpMethod, $className);
            }

            $seenMethods[$normalizedMethod] = true;
        }
    }

    private function validateClassReference(string $class, string $field): void
    {
        foreach (explode('\\', $class) as $segment) {
            $this->validateIdentifier($segment, $field, $class);
        }
    }

    private function validateIdentifier(string $identifier, string $field, string $value): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier) !== 1) {
            throw SkirScaffoldingException::invalidIdentifier($field, $value);
        }
    }

    private function controllerNamespace(): string
    {
        $configuredNamespace = config('skir-server.scaffolding.controller_namespace');

        if (! is_string($configuredNamespace)) {
            throw SkirScaffoldingException::invalidControllerNamespace($configuredNamespace);
        }

        $namespace = trim($configuredNamespace, '\\');
        $this->validateClassReference($namespace, 'controller_namespace');
        $this->validateApplicationNamespace($namespace);

        return $namespace;
    }

    private function singleControllerClass(?string $configuredController): string
    {
        if ($configuredController === null) {
            return $this->controllerNamespace().'\\SkirController';
        }

        $controller = str_contains($configuredController, '\\')
            ? trim($configuredController, '\\')
            : $this->controllerNamespace()."\\{$configuredController}";

        try {
            $this->validateClassReference($controller, 'singleController');
            $this->validateApplicationNamespace($controller);
        } catch (SkirScaffoldingException) {
            throw SkirScaffoldingException::invalidSingleController($configuredController);
        }

        return $controller;
    }

    private function validateApplicationNamespace(string $namespace): void
    {
        $namespaceSegments = explode('\\', $namespace);
        $applicationNamespace = $this->applicationNamespace();
        $applicationSegments = explode('\\', $applicationNamespace);

        if (array_slice($namespaceSegments, 0, count($applicationSegments)) !== $applicationSegments) {
            throw SkirScaffoldingException::controllerNamespaceOutsideApplication($namespace, $applicationNamespace);
        }
    }

    private function destinationPath(string $namespace, string $className): string
    {
        $namespaceSegments = explode('\\', $namespace);
        $applicationNamespaceSegments = explode('\\', $this->applicationNamespace());
        $relativeSegments = array_slice($namespaceSegments, count($applicationNamespaceSegments));

        return app_path(implode(DIRECTORY_SEPARATOR, [...$relativeSegments, "{$className}.php"]));
    }

    private function applicationNamespace(): string
    {
        try {
            return trim(app()->getNamespace(), '\\');
        } catch (RuntimeException) {
            return 'App';
        }
    }

    /** @param array<string, string> $replacements */
    private function renderStub(string $stubName, array $replacements): string
    {
        $stub = $this->filesystem->read(dirname(__DIR__, 2)."/stubs/{$stubName}");

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    private function publishCreated(RenderedFile $rendered, bool $request): FileSnapshot
    {
        $directory = dirname($rendered->destinationPath);

        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory);
        }

        $temporaryPath = $this->filesystem->temporaryFile($directory);
        $published = false;
        $staged = null;

        try {
            $this->filesystem->write($temporaryPath, $rendered->source);
            $this->filesystem->chmod($temporaryPath, 0666 & ~umask());
            $staged = $this->filesystem->snapshot($temporaryPath);

            if ($staged === null) {
                throw SkirScaffoldingException::rollbackArtifactChanged($rendered->destinationPath);
            }

            $this->publisher->publish(
                $temporaryPath,
                $rendered->destinationPath,
                $request
                    ? SkirScaffoldingException::existingFile(...)
                    : SkirScaffoldingException::existingController(...),
                $request
                    ? SkirScaffoldingException::atomicPublicationUnavailable(...)
                    : SkirScaffoldingException::controllerAtomicPublicationUnavailable(...),
            );
            $published = true;
            $destination = $this->filesystem->snapshot($rendered->destinationPath);

            if ($destination === null || ! $staged->matches($destination)) {
                throw SkirScaffoldingException::rollbackArtifactChanged($rendered->destinationPath);
            }
        } catch (Throwable $exception) {
            if ($published && $staged !== null) {
                try {
                    $this->undoUnjournaledCreation($rendered->destinationPath, $staged);
                } catch (Throwable $rollbackException) {
                    throw SkirScaffoldingException::transactionRollbackFailed($exception, $rollbackException);
                }
            }

            throw $exception;
        } finally {
            try {
                $this->filesystem->remove($temporaryPath);
            } catch (SkirScaffoldingException $exception) {
                if ($published && $staged !== null) {
                    try {
                        $this->undoUnjournaledCreation($rendered->destinationPath, $staged);
                        $this->filesystem->remove($temporaryPath);
                    } catch (Throwable $rollbackException) {
                        throw SkirScaffoldingException::transactionRollbackFailed(
                            $exception,
                            $rollbackException,
                        );
                    }

                    throw ($request
                        ? SkirScaffoldingException::cleanupFailedAfterPublication(
                            $rendered->destinationPath,
                            $temporaryPath,
                            $exception,
                        )
                        : SkirScaffoldingException::cleanupFailedAfterControllerPublication(
                            $rendered->destinationPath,
                            $temporaryPath,
                            $exception,
                        ));
                }

                throw $exception;
            }
        }

        return $staged;
    }

    private function undoUnjournaledCreation(string $path, FileSnapshot $published): void
    {
        if (! $this->filesystem->exists($path)) {
            return;
        }

        if ($this->filesystem->removeIfUnchanged($path, $published)) {
            return;
        }

        throw SkirScaffoldingException::rollbackArtifactChanged($path);
    }

    /**
     * Replaces the controller path through displacement and no-clobber publication.
     *
     * This protects atomic path replacements and cooperative in-place writers. Portable PHP cannot
     * prevent a non-cooperating process from writing an already-open inode while it is displaced;
     * the displaced inode and contents are therefore validated immediately after the move.
     *
     * Mode bits are preserved. Ownership, ACLs, and extended attributes are filesystem-specific
     * and are not copied to the generated inode.
     */
    private function replace(RenderedController $controller): ControllerReplacement
    {
        $rendered = $controller->file;
        $original = $controller->originalSnapshot;

        if ($original === null) {
            throw SkirScaffoldingException::controllerChangedDuringScaffolding($rendered->destinationPath);
        }

        $directory = dirname($rendered->destinationPath);
        $temporaryPath = $this->filesystem->temporaryFile($directory);
        $backupPath = null;
        $staged = null;
        $displaced = false;
        $published = false;

        try {
            $this->filesystem->write($temporaryPath, $rendered->source);
            $this->filesystem->chmod($temporaryPath, $original->mode & 07777);
            $staged = $this->filesystem->snapshot($temporaryPath);

            if ($staged === null) {
                throw SkirScaffoldingException::rollbackArtifactChanged($rendered->destinationPath);
            }

            $backupPath = $this->filesystem->displaceToBackup($rendered->destinationPath);
            $displaced = true;
            $displacedSnapshot = $this->filesystem->snapshot($backupPath);

            if ($displacedSnapshot === null || ! $original->matches($displacedSnapshot)) {
                throw SkirScaffoldingException::controllerChangedDuringScaffolding($rendered->destinationPath);
            }

            $this->publisher->publish(
                $temporaryPath,
                $rendered->destinationPath,
                SkirScaffoldingException::existingController(...),
                SkirScaffoldingException::controllerAtomicPublicationUnavailable(...),
            );
            $published = true;
            $destination = $this->filesystem->snapshot($rendered->destinationPath);

            if ($destination === null || ! $staged->matches($destination)) {
                throw SkirScaffoldingException::rollbackArtifactChanged($rendered->destinationPath);
            }

            return new ControllerReplacement(
                $rendered->destinationPath,
                $backupPath,
                $staged,
            );
        } catch (Throwable $exception) {
            if ($displaced && $backupPath !== null) {
                try {
                    if ($published && $staged !== null) {
                        $this->undoPublishedReplacement($rendered->destinationPath, $staged);
                    }

                    $this->restoreDisplacedOriginal($backupPath, $rendered->destinationPath);
                } catch (Throwable $rollbackException) {
                    throw SkirScaffoldingException::transactionRollbackFailed($exception, $rollbackException);
                }
            }

            throw $exception;
        } finally {
            try {
                $this->filesystem->remove($temporaryPath);
            } catch (Throwable $cleanupException) {
                if ($published && $backupPath !== null && $staged !== null) {
                    try {
                        $this->rollbackReplacement(new ControllerReplacement(
                            $rendered->destinationPath,
                            $backupPath,
                            $staged,
                        ));
                        $this->filesystem->remove($temporaryPath);
                    } catch (Throwable $rollbackException) {
                        throw SkirScaffoldingException::transactionRollbackFailed(
                            $cleanupException,
                            $rollbackException,
                        );
                    }
                }

                throw $cleanupException;
            }
        }
    }

    private function rollbackReplacement(ControllerReplacement $replacement): void
    {
        if (! $this->filesystem->removeIfUnchanged(
            $replacement->destinationPath,
            $replacement->published,
        )) {
            throw SkirScaffoldingException::displacedControllerPreserved(
                $replacement->destinationPath,
                $replacement->backupPath,
            );
        }

        if (! $this->filesystem->restoreBackup(
            $replacement->backupPath,
            $replacement->destinationPath,
        )) {
            throw SkirScaffoldingException::displacedControllerPreserved(
                $replacement->destinationPath,
                $replacement->backupPath,
            );
        }
    }

    private function undoPublishedReplacement(string $destinationPath, FileSnapshot $published): void
    {
        if (! $this->filesystem->exists($destinationPath)) {
            return;
        }

        if ($this->filesystem->removeIfUnchanged($destinationPath, $published)) {
            return;
        }

        throw SkirScaffoldingException::rollbackArtifactChanged($destinationPath);
    }

    private function restoreDisplacedOriginal(string $backupPath, string $destinationPath): void
    {
        if (! $this->filesystem->exists($destinationPath)) {
            if ($this->filesystem->restoreBackup($backupPath, $destinationPath)) {
                return;
            }
        }

        if ($this->filesystem->exists($backupPath)) {
            throw SkirScaffoldingException::displacedControllerPreserved($destinationPath, $backupPath);
        }

        if ($this->filesystem->exists($destinationPath)) {
            return;
        }

        throw SkirScaffoldingException::rollbackArtifactChanged($destinationPath);
    }

    /** @param list<string> $missingDirectories */
    private function rollbackCreated(
        string $path,
        FileSnapshot $published,
        array $missingDirectories,
    ): void {
        if (! $this->filesystem->removeIfUnchanged($path, $published)) {
            throw SkirScaffoldingException::rollbackArtifactChanged($path);
        }

        foreach ($missingDirectories as $missingDirectory) {
            $this->filesystem->removeDirectoryIfEmpty($missingDirectory);
        }
    }

    /** @param list<string> $directories */
    private function removeEmptyDirectories(array $directories): void
    {
        foreach ($directories as $directory) {
            $this->filesystem->removeDirectoryIfEmpty($directory);
        }
    }

    /** @return list<string> */
    private function missingDirectories(string $path): array
    {
        $directories = [];
        $directory = dirname($path);
        $applicationPath = rtrim(app_path(), DIRECTORY_SEPARATOR);

        while ($directory !== $applicationPath && str_starts_with($directory, $applicationPath.DIRECTORY_SEPARATOR)) {
            if (! $this->filesystem->isDirectory($directory)) {
                $directories[] = $directory;
            }

            $directory = dirname($directory);
        }

        return $directories;
    }

    /** @param array<string, FileSnapshot|null> $snapshots */
    private function revalidateSnapshots(array $snapshots): void
    {
        foreach ($snapshots as $path => $snapshot) {
            $this->revalidateSnapshot($path, $snapshot);
        }
    }

    private function revalidateSnapshot(string $path, ?FileSnapshot $expected): void
    {
        $current = $this->filesystem->snapshot($path);

        if ($expected !== null && $current !== null && $expected->matches($current)) {
            return;
        }

        throw SkirScaffoldingException::controllerChangedDuringScaffolding($path);
    }

    private function revalidateMissing(string $path): void
    {
        if (! $this->filesystem->exists($path)) {
            return;
        }

        throw SkirScaffoldingException::plannedOutputAppeared($path);
    }
}
