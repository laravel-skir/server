<?php

declare(strict_types=1);

namespace Skir\Server\Contracts;

use Skir\Server\PreparedProcedure;
use Skir\Server\SkirContext;

interface PreparesProcedure
{
    public function prepare(mixed $request, SkirContext $context): PreparedProcedure;
}
