<?php

declare(strict_types=1);

namespace Skir\Server\Hydration;

use InvalidArgumentException;

final class SkirPayloadHydrator
{
    public function supports(string $class): bool
    {
        return method_exists($class, 'makeFromSkirPayload')
            || method_exists($class, 'fromArray')
            || method_exists($class, 'fromSkirValue');
    }

    /** @param class-string $class */
    public function hydrate(string $class, mixed $payload): object
    {
        if (method_exists($class, 'makeFromSkirPayload')) {
            return $class::makeFromSkirPayload($payload);
        }

        if (method_exists($class, 'fromArray')) {
            return $class::fromArray($payload);
        }

        if (method_exists($class, 'fromSkirValue')) {
            return $class::fromSkirValue($payload);
        }

        throw new InvalidArgumentException("Class [{$class}] does not define a supported Skir payload factory.");
    }
}
