<?php

declare(strict_types=1);

namespace Skir\Server\Hydration;

use InvalidArgumentException;

final class SkirPayloadHydrator
{
    public function supports(string $class): bool
    {
        return is_callable([$class, 'makeFromSkirPayload'])
            || is_callable([$class, 'fromArray'])
            || is_callable([$class, 'fromSkirValue']);
    }

    /** @param class-string $class */
    public function hydrate(string $class, mixed $payload): object
    {
        if (is_callable([$class, 'makeFromSkirPayload'])) {
            return $class::makeFromSkirPayload($payload);
        }

        if (is_callable([$class, 'fromArray'])) {
            return $class::fromArray($payload);
        }

        if (is_callable([$class, 'fromSkirValue'])) {
            return $class::fromSkirValue($payload);
        }

        throw new InvalidArgumentException("Class [{$class}] does not define a supported Skir payload factory.");
    }
}
