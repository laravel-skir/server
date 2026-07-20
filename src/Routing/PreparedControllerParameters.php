<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

final readonly class PreparedControllerParameters
{
    /**
     * @param  list<mixed>  $values
     * @param  list<int>  $nullObjectIds
     */
    public function __construct(
        public array $values,
        private array $nullObjectIds = [],
    ) {}

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    public function restoreNulls(array $values): array
    {
        return array_map(
            fn (mixed $value): mixed => is_object($value)
                && in_array(spl_object_id($value), $this->nullObjectIds, true)
                    ? null
                    : $value,
            $values,
        );
    }
}
