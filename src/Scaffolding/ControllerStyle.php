<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

enum ControllerStyle: string
{
    case Module = 'module';
    case Invokable = 'invokable';
    case Single = 'single';
}
