<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use PhpParser\ParserFactory;
use RuntimeException;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Throwable;

final class RequestNamespaceValidator
{
    public function resolve(): string
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
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $namespaceSegment) !== 1) {
                throw SkirScaffoldingException::invalidRequestNamespace($configuredNamespace);
            }
        }

        try {
            (new ParserFactory)->createForHostVersion()->parse("<?php namespace {$configuredNamespace};");
        } catch (Throwable) {
            throw SkirScaffoldingException::invalidRequestNamespace($configuredNamespace);
        }

        $applicationNamespace = $this->applicationNamespace();
        $applicationNamespaceSegments = explode('\\', $applicationNamespace);

        if (array_slice($namespaceSegments, 0, count($applicationNamespaceSegments)) !== $applicationNamespaceSegments) {
            throw SkirScaffoldingException::namespaceOutsideApplication($configuredNamespace, $applicationNamespace);
        }

        return $configuredNamespace;
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
