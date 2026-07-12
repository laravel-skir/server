<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use PhpParser\ParserFactory;
use RuntimeException;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Throwable;

final class ControllerClassValidator
{
    public function resolve(string $configuredController): string
    {
        if (trim($configuredController) === '') {
            throw SkirScaffoldingException::invalidSingleController($configuredController);
        }

        $controller = str_contains($configuredController, '\\')
            ? trim($configuredController, '\\')
            : $this->controllerNamespace()."\\{$configuredController}";

        try {
            $this->validateClassReference($controller);
            $this->validateApplicationNamespace($controller);
            (new ParserFactory)->createForHostVersion()->parse("<?php {$controller}::class;");
        } catch (Throwable) {
            throw SkirScaffoldingException::invalidSingleController($configuredController);
        }

        return $controller;
    }

    private function controllerNamespace(): string
    {
        $configuredNamespace = config('skir-server.scaffolding.controller_namespace');

        if (! is_string($configuredNamespace)) {
            throw SkirScaffoldingException::invalidSingleController(get_debug_type($configuredNamespace));
        }

        $namespace = trim($configuredNamespace, '\\');

        try {
            $this->validateClassReference($namespace);
            $this->validateApplicationNamespace($namespace);
        } catch (SkirScaffoldingException) {
            throw SkirScaffoldingException::invalidSingleController($configuredNamespace);
        }

        return $namespace;
    }

    private function validateClassReference(string $class): void
    {
        foreach (explode('\\', $class) as $segment) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $segment) !== 1) {
                throw SkirScaffoldingException::invalidSingleController($class);
            }
        }
    }

    private function validateApplicationNamespace(string $class): void
    {
        $classSegments = explode('\\', $class);
        $applicationNamespace = $this->applicationNamespace();
        $applicationSegments = explode('\\', $applicationNamespace);

        if (array_slice($classSegments, 0, count($applicationSegments)) !== $applicationSegments) {
            throw SkirScaffoldingException::invalidSingleController($class);
        }
    }

    private function applicationNamespace(): string
    {
        try {
            return trim(app()->getNamespace(), '\\');
        } catch (RuntimeException) {
            return 'App';
        }
    }
}
