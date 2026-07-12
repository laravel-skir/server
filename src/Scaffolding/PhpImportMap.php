<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

final class PhpImportMap
{
    /** @var array<string, string> */
    private array $aliasesByClass = [];

    /** @var array<string, true> */
    private array $usedAliases = [];

    public function __construct(string $declaredClassName)
    {
        $this->usedAliases[strtolower($declaredClassName)] = true;
    }

    public function add(string $class): string
    {
        if (isset($this->aliasesByClass[$class])) {
            return $this->aliasesByClass[$class];
        }

        $segments = explode('\\', $class);
        $shortName = end($segments);
        $alias = $shortName;
        $prefixLength = 1;

        while (isset($this->usedAliases[strtolower($alias)])) {
            $prefix = $segments[count($segments) - 1 - $prefixLength] ?? 'Imported';
            $alias = $prefix.$alias;
            $prefixLength++;
        }

        $this->aliasesByClass[$class] = $alias;
        $this->usedAliases[strtolower($alias)] = true;

        return $alias;
    }

    public function alias(string $class): string
    {
        return $this->aliasesByClass[$class];
    }

    public function source(): string
    {
        $imports = [];

        foreach ($this->aliasesByClass as $class => $alias) {
            $shortName = class_basename($class);
            $imports[] = $alias === $shortName
                ? "use {$class};"
                : "use {$class} as {$alias};";
        }

        return implode("\n", $imports);
    }
}
