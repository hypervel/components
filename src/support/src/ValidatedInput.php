<?php

declare(strict_types=1);

namespace Hypervel\Support;

use ArrayIterator;
use Hypervel\Support\Contracts\ValidatedData;
use Hypervel\Support\Traits\InteractsWithData;
use Symfony\Component\VarDumper\VarDumper;
use Traversable;

class ValidatedInput implements ValidatedData
{
    use InteractsWithData;

    /**
     * Create a new validated input container.
     *
     * @param array $input the underlying input
     */
    public function __construct(
        protected array $input
    ) {
    }

    /**
     * Merge the validated input with the given array of additional data.
     */
    public function merge(array $items): static
    {
        return new static(array_merge($this->all(), $items));
    }

    /**
     * Get the raw, underlying input array.
     *
     * @param null|array|mixed $keys
     */
    public function all(mixed $keys = null): array
    {
        if (! $keys) {
            return $this->input;
        }

        $input = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($input, $key, Arr::get($this->input, $key));
        }

        return $input;
    }

    /**
     * Retrieve data from the instance.
     */
    protected function data(?string $key = null, mixed $default = null): mixed
    {
        return $this->input($key, $default);
    }

    /**
     * Get the keys for all of the input.
     */
    public function keys(): array
    {
        return array_keys($this->input());
    }

    /**
     * Retrieve an input item from the validated inputs.
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        return data_get(
            $this->all(),
            $key,
            $default
        );
    }

    /**
     * Dump the validated inputs items and end the script.
     *
     * @return never
     */
    public function dd(mixed ...$keys): void
    {
        $this->dump(...$keys);

        exit(1);
    }

    /**
     * Dump the items.
     */
    public function dump(mixed $keys = []): static
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        VarDumper::dump(count($keys) > 0 ? $this->only($keys) : $this->all());

        return $this;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->all();
    }

    /**
     * Dynamically access input data.
     */
    public function __get(string $name): mixed
    {
        return $this->input($name);
    }

    /**
     * Dynamically set input data.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->input[$name] = $value;
    }

    /**
     * Determine if an input item is set.
     */
    public function __isset(string $name): bool
    {
        return $this->exists($name);
    }

    /**
     * Remove an input item.
     */
    public function __unset(string $name): void
    {
        unset($this->input[$name]);
    }

    /**
     * Determine if an item exists at an offset.
     */
    public function offsetExists(mixed $key): bool
    {
        return $this->exists($key);
    }

    /**
     * Get an item at a given offset.
     */
    public function offsetGet(mixed $key): mixed
    {
        return $this->input($key);
    }

    /**
     * Set the item at a given offset.
     */
    public function offsetSet(mixed $key, mixed $value): void
    {
        if (is_null($key)) {
            $this->input[] = $value;
        } else {
            $this->input[$key] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     */
    public function offsetUnset(mixed $key): void
    {
        unset($this->input[$key]);
    }

    /**
     * Get an iterator for the input.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->input);
    }
}
