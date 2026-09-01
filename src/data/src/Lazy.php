<?php

declare(strict_types=1);

namespace Hypervel\Data;

use Closure;
use Hypervel\Data\Support\Lazy\ClosureLazy;
use Hypervel\Data\Support\Lazy\ConditionalLazy;
use Hypervel\Data\Support\Lazy\DefaultLazy;
use Hypervel\Data\Support\Lazy\InertiaDeferred;
use Hypervel\Data\Support\Lazy\InertiaLazy;
use Hypervel\Data\Support\Lazy\RelationalLazy;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Traits\Macroable;

abstract class Lazy
{
    use Macroable {
        __call as protected callMacro;
    }

    protected ?bool $defaultIncluded = null;

    /**
     * Create a lazy value.
     */
    public static function create(Closure $value): DefaultLazy
    {
        return new DefaultLazy($value);
    }

    /**
     * Create a conditionally included lazy value.
     */
    public static function when(Closure $condition, Closure $value): ConditionalLazy
    {
        return new ConditionalLazy($condition, $value);
    }

    /**
     * Create a lazy value for a loaded model relation.
     */
    public static function whenLoaded(string $relation, Model $model, Closure $value): RelationalLazy
    {
        return new RelationalLazy($relation, $model, $value);
    }

    /**
     * Create an Inertia lazy prop.
     */
    public static function inertia(Closure $value): InertiaLazy
    {
        return new InertiaLazy($value);
    }

    /**
     * Create an Inertia deferred prop.
     */
    public static function inertiaDeferred(mixed $value, ?string $group = null): InertiaDeferred
    {
        return new InertiaDeferred($value, $group);
    }

    /**
     * Lazily expose a closure as the resolved value.
     */
    public static function closure(Closure $closure): ClosureLazy
    {
        return new ClosureLazy($closure);
    }

    /**
     * Resolve the lazy value.
     */
    abstract public function resolve(): mixed;

    /**
     * Get the serializable lazy state.
     */
    abstract public function __serialize(): array;

    /**
     * Restore serialized lazy state.
     */
    abstract public function __unserialize(array $data): void;

    /**
     * Include this value without an explicit partial selection.
     */
    public function defaultIncluded(bool $defaultIncluded = true): self
    {
        $this->defaultIncluded = $defaultIncluded;

        return $this;
    }

    /**
     * Determine if this value is included by default.
     */
    public function isDefaultIncluded(): bool
    {
        return $this->defaultIncluded ?? false;
    }

    /**
     * Get an intrinsic inclusion decision when the lazy type owns one.
     */
    public function shouldBeIncluded(): ?bool
    {
        return null;
    }

    /**
     * Forward property access to the resolved value.
     */
    public function __get(string $name): mixed
    {
        return $this->resolve()->$name;
    }

    /**
     * Run a registered macro or forward the call to the resolved value.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (static::hasMacro($name)) {
            return $this->callMacro($name, $arguments);
        }

        return call_user_func_array([$this->resolve(), $name], $arguments);
    }
}
