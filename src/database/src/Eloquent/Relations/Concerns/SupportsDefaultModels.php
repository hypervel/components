<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Relations\Concerns;

use Closure;
use Hyperf\Database\Model\Model;

trait SupportsDefaultModels
{
    /**
     * Indicates if a default model instance should be used.
     *
     * Alternatively, may be a Closure or array.
     *
     * @var Closure|array|bool
     */
    protected Closure|array|bool $withDefault = false;

    /**
     * Make a new related instance for the given model.
     */
    abstract protected function newRelatedInstanceFor(Model $parent): Model;

    /**
     * Return a new model instance in case the relationship does not exist.
     *
     * @return $this
     */
    public function withDefault(Closure|array|bool $callback = true): static
    {
        $this->withDefault = $callback;

        return $this;
    }

    /**
     * Get the default value for this relation.
     */
    protected function getDefaultFor(Model $parent): ?Model
    {
        if (! $this->withDefault) {
            return null;
        }

        $instance = $this->newRelatedInstanceFor($parent);

        if (is_callable($this->withDefault)) {
            return call_user_func($this->withDefault, $instance, $parent) ?: $instance;
        }

        if (is_array($this->withDefault)) {
            $instance->forceFill($this->withDefault);
        }

        return $instance;
    }
}
