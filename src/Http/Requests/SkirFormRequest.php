<?php

declare(strict_types=1);

namespace Skir\Server\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Skir\Server\Hydration\SkirPayloadHydrator;

/** @template TSkir of object */
abstract class SkirFormRequest extends FormRequest
{
    final public function authorizeResolved(): void
    {
        $this->prepareForValidation();

        if (! $this->passesAuthorization()) {
            $this->failedAuthorization();
        }
    }

    /** @return TSkir */
    final public function skir(): object
    {
        $hydrator = $this->container->make(SkirPayloadHydrator::class);
        $preparedPayload = $this->all();

        return $hydrator->hydrate($this->skirClass(), $preparedPayload);
    }

    /** @return class-string<TSkir> */
    abstract protected function skirClass(): string;
}
