<?php

declare(strict_types=1);

namespace Skir\Server\Contracts;

use Skir\Runtime\MethodDescriptor;

interface SkirMethodReference
{
    public function descriptor(): MethodDescriptor;
}
