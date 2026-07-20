<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

interface ControllerScaffolding
{
    public function scaffold(ScaffoldingSelection $selection): ControllerScaffoldingResult;
}
