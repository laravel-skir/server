<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use Skir\Server\Attributes\SkirMethod;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Skir\Server\SkirContext;

final class ControllerMethodRenderer
{
    /**
     * @param  list<SkirMethodDefinition>  $methods
     * @param  array<string, string>  $requestClasses
     */
    public function addImports(
        PhpImportMap $imports,
        array $methods,
        array $requestClasses,
    ): void {
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
    }

    public function render(
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
}
