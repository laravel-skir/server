<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

final class PhpImportMap
{
    /** @var array<string, string> */
    private array $aliasesByClass = [];

    /** @var array<string, string> */
    private array $classesByNormalizedName = [];

    /** @var array<string, true> */
    private array $usedAliases = [];

    /** @var array<string, string> */
    private array $newAliasesByClass = [];

    public function __construct(string $declaredClassName)
    {
        $this->usedAliases[strtolower($declaredClassName)] = true;
    }

    public function add(string $class): string
    {
        $normalizedClass = strtolower(ltrim($class, '\\'));

        if (isset($this->classesByNormalizedName[$normalizedClass])) {
            return $this->aliasesByClass[$this->classesByNormalizedName[$normalizedClass]];
        }

        $segments = explode('\\', $class);
        $shortName = end($segments);
        $alias = $shortName;
        $prefixLength = 1;

        while (isset($this->usedAliases[strtolower($alias)])) {
            $prefixIndex = count($segments) - 1 - $prefixLength;

            if ($prefixIndex >= 0) {
                $alias = $segments[$prefixIndex].$alias;
                $prefixLength++;

                continue;
            }

            $fallback = 'Imported'.$shortName;
            $suffix = 1;
            $alias = $fallback;

            while (isset($this->usedAliases[strtolower($alias)])) {
                $suffix++;
                $alias = $fallback.$suffix;
            }
        }

        $this->aliasesByClass[$class] = $alias;
        $this->classesByNormalizedName[$normalizedClass] = $class;
        $this->newAliasesByClass[$class] = $alias;
        $this->usedAliases[strtolower($alias)] = true;

        return $alias;
    }

    public function registerExisting(string $class, string $alias): void
    {
        $this->aliasesByClass[$class] = $alias;
        $this->classesByNormalizedName[strtolower(ltrim($class, '\\'))] = $class;
        $this->usedAliases[strtolower($alias)] = true;
    }

    public function reserveAlias(string $alias): void
    {
        $this->usedAliases[strtolower($alias)] = true;
    }

    public function alias(string $class): string
    {
        $registeredClass = $this->classesByNormalizedName[strtolower(ltrim($class, '\\'))];

        return $this->aliasesByClass[$registeredClass];
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

    /** @return array<string, string> */
    public function newImports(): array
    {
        return $this->newAliasesByClass;
    }
}
