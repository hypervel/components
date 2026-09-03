<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Lazy;

use Closure;
use Hypervel\Data\Lazy;
use Laravel\SerializableClosure\SerializableClosure;

class ConditionalLazy extends Lazy
{
    protected function __construct(
        protected Closure $condition,
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
     * Determine if the value's own condition includes it.
     */
    public function shouldBeIncluded(): bool
    {
        return (bool) ($this->condition)();
    }

    /**
     * Get the serializable lazy state.
     */
    public function __serialize(): array
    {
        return [
            'condition' => new SerializableClosure($this->condition),
            'value' => new SerializableClosure($this->value),
            'defaultIncluded' => $this->defaultIncluded,
        ];
    }

    /**
     * Restore serialized lazy state.
     */
    public function __unserialize(array $data): void
    {
        $this->condition = $data['condition']->getClosure();
        $this->value = $data['value']->getClosure();
        $this->defaultIncluded = $data['defaultIncluded'];
    }
}
