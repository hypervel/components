<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Lazy;

use Closure;
use Hypervel\Data\Lazy;
use Laravel\SerializableClosure\SerializableClosure;

class DefaultLazy extends Lazy
{
    protected function __construct(
        protected Closure $value,
    ) {
    }

    /**
     * Resolve the lazy value.
     */
    public function resolve(): mixed
    {
        return ($this->value)();
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
