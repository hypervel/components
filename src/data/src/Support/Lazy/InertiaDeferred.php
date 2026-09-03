<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Lazy;

use Closure;
use Hypervel\Data\Lazy;
use Hypervel\Inertia\DeferProp;
use Laravel\SerializableClosure\SerializableClosure;

class InertiaDeferred extends Lazy
{
    protected DeferProp|Closure $value;

    /**
     * Create a new Inertia deferred value.
     */
    public function __construct(
        mixed $value,
        protected ?string $group = null,
        protected bool $rescue = false,
    ) {
        $this->value = match (true) {
            $value instanceof DeferProp => $value,
            is_callable($value) => Closure::fromCallable($value),
            default => fn (): mixed => $value,
        };
    }

    /**
     * Resolve the Inertia property.
     */
    public function resolve(): DeferProp
    {
        return $this->value instanceof DeferProp
            ? $this->value
            : new DeferProp($this->value, $this->group, $this->rescue);
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
            'value' => $this->value instanceof Closure
                ? new SerializableClosure($this->value)
                : $this->value,
            'group' => $this->group,
            'rescue' => $this->rescue,
            'defaultIncluded' => $this->defaultIncluded,
        ];
    }

    /**
     * Restore serialized lazy state.
     */
    public function __unserialize(array $data): void
    {
        $this->value = $data['value'] instanceof SerializableClosure
            ? $data['value']->getClosure()
            : $data['value'];
        $this->group = $data['group'];
        $this->rescue = $data['rescue'];
        $this->defaultIncluded = $data['defaultIncluded'];
    }
}
