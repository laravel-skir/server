<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use PhpParser\ParserFactory;
use RuntimeException;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Throwable;

final class FormRequestScaffolder
{
    public function __construct(
        private readonly AtomicFilePublisher $publisher,
        private readonly ScaffoldingFilesystem $filesystem,
    ) {}

    public function render(SkirMethodDefinition $method, ?string $className = null): RenderedFile
    {
        if ($method->requestClass === null) {
            throw SkirScaffoldingException::missingObjectRequestClass($method->id());
        }

        $namespace = $this->requestNamespace($method);
        $resolvedClassName = $className ?? "{$method->name}FormRequest";
        $this->validateClassName($resolvedClassName);
        $this->validateClassReference($method->requestClass, 'requestClass');

        $requestClassName = class_basename($method->requestClass);
        $this->validateImports($resolvedClassName, $requestClassName);
        $destinationPath = $this->destinationPath($namespace, $resolvedClassName);
        $source = $this->renderStub([
            '{{ namespace }}' => $namespace,
            '{{ requestClass }}' => $method->requestClass,
            '{{ requestClassName }}' => $requestClassName,
            '{{ className }}' => $resolvedClassName,
        ]);

        try {
            (new ParserFactory)->createForHostVersion()->parse($source);
        } catch (Throwable $exception) {
            throw SkirScaffoldingException::invalidRenderedSource($destinationPath, $exception);
        }

        return new RenderedFile($destinationPath, $source);
    }

    public function scaffold(SkirMethodDefinition $method, ?string $className = null): string
    {
        return $this->publish($this->render($method, $className));
    }

    public function publish(RenderedFile $rendered): string
    {
        if ($this->filesystem->exists($rendered->destinationPath)) {
            throw SkirScaffoldingException::existingFile($rendered->destinationPath);
        }

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
                SkirScaffoldingException::existingFile(...),
            );
            $published = true;
        } finally {
            try {
                $this->filesystem->remove($temporaryPath);
            } catch (SkirScaffoldingException $exception) {
                if ($published) {
                    throw SkirScaffoldingException::cleanupFailedAfterPublication(
                        $rendered->destinationPath,
                        $temporaryPath,
                        $exception,
                    );
                }

                throw $exception;
            }
        }

        return $rendered->destinationPath;
    }

    private function requestNamespace(SkirMethodDefinition $method): string
    {
        $configuredNamespace = config('skir-server.scaffolding.request_namespace');

        if (! is_string($configuredNamespace)) {
            throw SkirScaffoldingException::invalidRequestNamespace($configuredNamespace);
        }

        $namespaceSegments = explode('\\', $configuredNamespace);

        if ($configuredNamespace === '' || in_array('', $namespaceSegments, true)) {
            throw SkirScaffoldingException::invalidRequestNamespace($configuredNamespace);
        }

        foreach ($namespaceSegments as $namespaceSegment) {
            if (! $this->isIdentifier($namespaceSegment)) {
                throw SkirScaffoldingException::invalidRequestNamespace($configuredNamespace);
            }
        }

        $applicationNamespace = $this->applicationNamespace();
        $applicationNamespaceSegments = explode('\\', $applicationNamespace);

        if (array_slice($namespaceSegments, 0, count($applicationNamespaceSegments)) !== $applicationNamespaceSegments) {
            throw SkirScaffoldingException::namespaceOutsideApplication($configuredNamespace, $applicationNamespace);
        }

        $moduleSegments = explode('.', $method->module);

        foreach ($moduleSegments as $moduleSegment) {
            if (! $this->isIdentifier($moduleSegment)) {
                throw SkirScaffoldingException::invalidIdentifier('module', $method->module);
            }
        }

        return implode('\\', [...$namespaceSegments, ...$moduleSegments]);
    }

    private function destinationPath(string $namespace, string $className): string
    {
        $namespaceSegments = explode('\\', $namespace);
        $applicationNamespaceSegments = explode('\\', $this->applicationNamespace());

        if (array_slice($namespaceSegments, 0, count($applicationNamespaceSegments)) === $applicationNamespaceSegments) {
            $namespaceSegments = array_slice($namespaceSegments, count($applicationNamespaceSegments));
        }

        return app_path(implode(DIRECTORY_SEPARATOR, [...$namespaceSegments, "{$className}.php"]));
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
    private function renderStub(array $replacements): string
    {
        $stubPath = dirname(__DIR__, 2).'/stubs/skir-form-request.stub';
        $stub = $this->filesystem->read($stubPath);

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    private function validateClassName(string $className): void
    {
        if (! $this->isIdentifier($className)) {
            throw SkirScaffoldingException::invalidClassName($className);
        }

        try {
            (new ParserFactory)->createForHostVersion()->parse("<?php final class {$className} {}");
        } catch (Throwable) {
            throw SkirScaffoldingException::invalidClassName($className);
        }
    }

    private function validateClassReference(string $class, string $field): void
    {
        foreach (explode('\\', $class) as $segment) {
            if (! $this->isIdentifier($segment)) {
                throw SkirScaffoldingException::invalidIdentifier($field, $class);
            }
        }
    }

    private function validateImports(string $className, string $requestClassName): void
    {
        $shortNames = [$className, $requestClassName, 'ValidationRule', 'SkirFormRequest'];
        $seenShortNames = [];

        foreach ($shortNames as $shortName) {
            $normalizedShortName = strtolower($shortName);

            if (isset($seenShortNames[$normalizedShortName])) {
                throw SkirScaffoldingException::importCollision($shortName);
            }

            $seenShortNames[$normalizedShortName] = true;
        }
    }

    private function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $value) === 1;
    }
}
