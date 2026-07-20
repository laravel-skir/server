<?php

declare(strict_types=1);

namespace Skir\Server\Hydration;

use InvalidArgumentException;
use ReflectionMethod;

final class SkirPayloadHydrator
{
    public function supports(string $class): bool
    {
        return $this->hasFactory($class, 'makeFromSkirPayload')
            || $this->hasFactory($class, 'fromArray')
            || $this->hasFactory($class, 'fromSkirValue');
    }

    /** @param class-string $class */
    public function hydrate(string $class, mixed $payload): object
    {
        if ($this->hasFactory($class, 'makeFromSkirPayload')) {
            return $class::makeFromSkirPayload($payload);
        }

        if ($this->hasFactory($class, 'fromArray')) {
            return $class::fromArray($payload);
        }

        if ($this->hasFactory($class, 'fromSkirValue')) {
            return $class::fromSkirValue($payload);
        }

        throw new InvalidArgumentException("Class [{$class}] does not define a supported Skir payload factory.");
    }

    private function hasFactory(string $class, string $factory): bool
    {
        if (! method_exists($class, $factory)) {
            return false;
        }

        $method = new ReflectionMethod($class, $factory);

        if (! $method->isPublic()) {
            return false;
        }

        if (! $method->isStatic()) {
            return false;
        }

        return ! $method->isAbstract();
    }
}
