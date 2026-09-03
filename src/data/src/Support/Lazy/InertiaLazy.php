<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Lazy;

use Closure;
use Hypervel\Data\Lazy;
use Hypervel\Inertia\OptionalProp;
use Laravel\SerializableClosure\SerializableClosure;

class InertiaLazy extends Lazy
{
    /**
     * Create a new Inertia lazy value.
     */
    protected function __construct(
        protected Closure $value,
    ) {
    }

    /**
     * Resolve the Inertia property.
     */
    public function resolve(): OptionalProp
    {
        return new OptionalProp($this->value);
    }

    /**
     * Determine if the Inertia property is intrinsically included.
     */
    public function shouldBeIncluded(): bool
    {
        return true;
    }

    /**
     * Determine if resolving this lazy value produces data.
     */
    public function resolvesToData(): bool
    {
        return false;
    }

    /**
     * Get the serializable lazy state.
     */
    public function __serialize(): array
    {
        return [
            'value' => new SerializableClosure($this->value),
            'defaultIncluded' => $this->defaultIncluded,
        ];
    }

    /**
     * Restore serialized lazy state.
     */
    public function __unserialize(array $data): void
    {
        $this->value = $data['value']->getClosure();
        $this->defaultIncluded = $data['defaultIncluded'];
    }
}
