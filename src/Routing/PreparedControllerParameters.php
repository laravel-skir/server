<?php

declare(strict_types=1);

namespace Skir\Server\Routing;

final readonly class PreparedControllerParameters
{
    /**
     * @param  list<mixed>  $values
     * @param  list<int>  $nullParameterIndexes
     */
    public function __construct(
        public array $values,
        private array $nullParameterIndexes = [],
    ) {}

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    public function restoreNullParameters(array $values): array
    {
        foreach ($this->nullParameterIndexes as $parameterIndex) {
            if (array_key_exists($parameterIndex, $values)) {
                $values[$parameterIndex] = null;
            }
        }

        return $values;
    }
}
