<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use PhpParser\ParserFactory;
use RuntimeException;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Skir\Server\SkirContext;
use Throwable;

final class ControllerScaffolder
{
    public function __construct(
        private readonly AtomicFilePublisher $publisher,
        private readonly ScaffoldingFilesystem $filesystem,
        private readonly FormRequestScaffolder $formRequestScaffolder,
    ) {}

    public function scaffold(ScaffoldingSelection $selection): ControllerScaffoldingResult
    {
        $renderedRequests = $this->renderRequests($selection);
        $requestClasses = $this->requestClasses($selection);
        $renderedControllers = $this->renderControllers($selection, $requestClasses);
        $unchangedPaths = [];
        $requestsToCreate = [];

        foreach ($renderedRequests as $methodId => $renderedRequest) {
            if ($this->filesystem->exists($renderedRequest->destinationPath)) {
                $unchangedPaths[] = $renderedRequest->destinationPath;

                continue;
            }

            $requestsToCreate[$methodId] = $renderedRequest;
        }

        foreach ($renderedControllers as $renderedController) {
            if ($this->filesystem->exists($renderedController->file->destinationPath)) {
                throw SkirScaffoldingException::existingController($renderedController->file->destinationPath);
            }
        }

        $createdPaths = [];

        foreach ($requestsToCreate as $renderedRequest) {
            $createdPaths[] = $this->formRequestScaffolder->publish($renderedRequest);
        }

        foreach ($renderedControllers as $renderedController) {
            $this->publish($renderedController->file);
            $createdPaths[] = $renderedController->file->destinationPath;
        }

        return new ControllerScaffoldingResult(
            $createdPaths,
            $unchangedPaths,
            array_merge(...array_map(
                static fn (RenderedController $controller): array => $controller->routeHints,
                $renderedControllers,
            )),
        );
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
            $controllers[] = $this->renderController(
                $namespace,
                $className,
                $moduleMethods,
                $requestClasses,
                false,
                ["Skir::controller({$className}::class)"],
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
            $enumShortName = class_basename($method->enumClass);
            $controllers[] = $this->renderController(
                $namespace,
                $className,
                [$method],
                $requestClasses,
                true,
                ["Skir::method({$enumShortName}::{$method->enumCase}, {$className}::class)"],
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
            ["Skir::controller({$className}::class)"],
        );
    }

    /**
     * @param  list<SkirMethodDefinition>  $methods
     * @param  array<string, string>  $requestClasses
     * @param  list<string>  $routeHints
     */
    private function renderController(
        string $namespace,
        string $className,
        array $methods,
        array $requestClasses,
        bool $invokable,
        array $routeHints,
    ): RenderedController {
        $this->validateControllerMethods($className, $methods, $invokable);
        $imports = new PhpImportMap($className);
        $imports->add('LogicException');
        $imports->add(SkirMethod::class);
        $imports->add(SkirContext::class);

        foreach ($methods as $method) {
            $imports->add($method->enumClass);
            $requestClass = $requestClasses[$method->id()] ?? $method->requestClass;

            if ($requestClass !== null) {
                $imports->add($requestClass);
            }

            if ($method->responseClass !== null) {
                $imports->add($method->responseClass);
            }
        }

        $methodSource = [];

        foreach ($methods as $method) {
            $methodSource[] = $this->renderMethod(
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

        try {
            (new ParserFactory)->createForHostVersion()->parse($source);
        } catch (Throwable $exception) {
            throw SkirScaffoldingException::invalidRenderedController($destinationPath, $exception);
        }

        return new RenderedController(
            new RenderedFile($destinationPath, $source),
            $className,
            $methods,
            $this->resolvedRouteHints($routeHints, $methods, $imports, $invokable),
        );
    }

    private function renderMethod(
        SkirMethodDefinition $method,
        PhpImportMap $imports,
        ?string $formRequestClass,
        bool $invokable,
    ): string {
        $enumClass = $imports->alias($method->enumClass);
        $requestType = $formRequestClass === null
            ? $this->resolveManifestType($method->requestType, $method->requestClass, $imports)
            : $imports->alias($formRequestClass);
        $responseType = $this->resolveManifestType($method->responseType, $method->responseClass, $imports);
        $phpMethod = $invokable ? '__invoke' : $method->phpMethod;
        $requestParameter = $requestType === 'void' ? '' : "{$requestType} \$request, ";
        $attribute = $imports->alias(SkirMethod::class);
        $context = $imports->alias(SkirContext::class);
        $logicException = $imports->alias('LogicException');

        return <<<PHP
    #[{$attribute}({$enumClass}::{$method->enumCase})]
    public function {$phpMethod}({$requestParameter}{$context} \$context): {$responseType}
    {
        throw new {$logicException}('Skir method [{$method->id()}] is not implemented.');
    }
PHP;
    }

    private function resolveManifestType(
        string $manifestType,
        ?string $class,
        PhpImportMap $imports,
    ): string {
        if ($class === null) {
            return $manifestType;
        }

        $shortName = class_basename($class);
        $resolvedType = preg_replace(
            '/(?<![a-zA-Z0-9_])'.preg_quote($shortName, '/').'(?![a-zA-Z0-9_])/',
            $imports->alias($class),
            $manifestType,
        );

        return is_string($resolvedType) ? $resolvedType : $manifestType;
    }

    /**
     * @param  list<string>  $routeHints
     * @param  list<SkirMethodDefinition>  $methods
     * @return list<string>
     */
    private function resolvedRouteHints(
        array $routeHints,
        array $methods,
        PhpImportMap $imports,
        bool $invokable,
    ): array {
        if (! $invokable) {
            return $routeHints;
        }

        $method = $methods[0];
        $enumAlias = $imports->alias($method->enumClass);

        return [str_replace(class_basename($method->enumClass), $enumAlias, $routeHints[0])];
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

    private function publish(RenderedFile $rendered): void
    {
        $directory = dirname($rendered->destinationPath);

        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory);
        }

        $temporaryPath = $this->filesystem->temporaryFile($directory);
        $published = false;

        try {
            $this->filesystem->write($temporaryPath, $rendered->source);
            $this->filesystem->chmod($temporaryPath, 0666 & ~umask());
            $this->publisher->publish(
                $temporaryPath,
                $rendered->destinationPath,
                SkirScaffoldingException::existingController(...),
            );
            $published = true;
        } finally {
            try {
                $this->filesystem->remove($temporaryPath);
            } catch (SkirScaffoldingException $exception) {
                if ($published) {
                    throw SkirScaffoldingException::cleanupFailedAfterControllerPublication(
                        $rendered->destinationPath,
                        $temporaryPath,
                        $exception,
                    );
                }

                throw $exception;
            }
        }
    }
}
