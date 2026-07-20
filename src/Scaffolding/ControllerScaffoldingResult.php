<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

final readonly class ControllerScaffoldingResult
{
    /** @var list<string> */
    public array $routeHints;

    /**
     * @param  list<string>  $createdPaths
     * @param  list<string>  $unchangedPaths
     * @param  list<RouteRegistration>  $registrations
     * @param  list<string>  $updatedPaths
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $createdPaths,
        public array $unchangedPaths,
        public array $registrations,
        public array $updatedPaths = [],
        public array $warnings = [],
    ) {
        $this->routeHints = array_map(
            static fn (RouteRegistration $registration): string => $registration->snippet(),
            $registrations,
        );
    }
}
