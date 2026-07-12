<?php

declare(strict_types=1);

namespace Skir\Server\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Skir\Server\Hydration\SkirPayloadHydrator;

/** @template TSkir of object */
abstract class SkirFormRequest extends FormRequest
{
    /** @return TSkir */
    final public function skir(): object
    {
        $hydrator = $this->container->make(SkirPayloadHydrator::class);

        return $hydrator->hydrate($this->skirClass(), $this->all());
    }

    /** @return class-string<TSkir> */
    abstract protected function skirClass(): string;
}
