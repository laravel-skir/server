<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;

final readonly class ScaffoldingSelection
{
    /** @param list<SkirMethodDefinition> $methods */
    public function __construct(
        public array $methods,
        public ControllerStyle $style,
        public ?string $singleController,
        public bool $withFormRequests,
    ) {
        if ($methods === []) {
            throw SkirScaffoldingException::emptyControllerSelection();
        }

        if (! array_is_list($methods)) {
            throw SkirScaffoldingException::invalidControllerSelectionMethodList();
        }

        $selectedMethodIds = [];
        $selectedIdentities = [];

        foreach ($methods as $method) {
            if (! $method instanceof SkirMethodDefinition) {
                throw SkirScaffoldingException::invalidControllerSelectionMethod();
            }

            if (isset($selectedMethodIds[$method->id()])) {
                throw SkirScaffoldingException::duplicateControllerSelectionMethod($method->id());
            }

            $selectedMethodIds[$method->id()] = true;
            $identityKey = strtolower($method->enumClass.'::'.$method->enumCase);

            if (isset($selectedIdentities[$identityKey])) {
                throw SkirScaffoldingException::duplicateControllerSelectionIdentity(
                    $selectedIdentities[$identityKey]->id(),
                    $method->id(),
                    $selectedIdentities[$identityKey]->enumClass.'::'.$selectedIdentities[$identityKey]->enumCase,
                );
            }

            $selectedIdentities[$identityKey] = $method;
        }

        if ($singleController !== null && $style !== ControllerStyle::Single) {
            throw SkirScaffoldingException::invalidSingleControllerCombination();
        }
    }
}
