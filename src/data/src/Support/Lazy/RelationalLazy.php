<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Lazy;

use Closure;
use Hypervel\Data\Lazy;
use Hypervel\Database\Eloquent\Model;
use Laravel\SerializableClosure\SerializableClosure;

class RelationalLazy extends Lazy
{
    /**
     * Create a lazy relationship value.
     */
    protected function __construct(
        protected string $relation,
        protected Model $model,
        protected Closure $value,
    ) {
    }

    /**
     * Resolve the relationship value.
     */
    public function resolve(): mixed
    {
        return $this->model->{$this->relation} !== null ? ($this->value)() : null;
    }

    /**
     * Determine if the relationship is loaded.
     */
    public function shouldBeIncluded(): bool
    {
        return $this->model->relationLoaded($this->relation);
    }

    /**
     * Get the serializable lazy state.
     */
    public function __serialize(): array
    {
        return [
            'relation' => $this->relation,
            'model' => $this->model,
            'value' => new SerializableClosure($this->value),
            'defaultIncluded' => $this->defaultIncluded,
        ];
    }

    /**
     * Restore serialized lazy state.
     */
    public function __unserialize(array $data): void
    {
        $this->relation = $data['relation'];
        $this->model = $data['model'];
        $this->value = $data['value']->getClosure();
        $this->defaultIncluded = $data['defaultIncluded'];
    }
}
