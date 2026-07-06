<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Contracts;

use LaravelSkir\Runtime\MethodDescriptor;

interface SkirMethodReference
{
    public function descriptor(): MethodDescriptor;
}
