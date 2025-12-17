<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Countable;
use Hypervel\Support\Contracts\MessageBag as MessageBagContract;
use Stringable;

/**
 * @mixin MessageBagContract
 */
class ViewErrorBag implements Countable, Stringable
{
    /**
     * The array of the view error bags.
     */
    protected array $bags = [];

    /**
     * Checks if a named MessageBag exists in the bags.
     */
    public function hasBag(string $key = 'default'): bool
    {
        return isset($this->bags[$key]);
    }

    /**
     * Get a MessageBag instance from the bags.
     */
    public function getBag(string $key)
    {
        return Arr::get($this->bags, $key) ?: new MessageBag;
    }

    /**
     * Get all the bags.
     */
    public function getBags(): array
    {
        return $this->bags;
    }

    /**
     * Add a new MessageBag instance to the bags.
     */
    public function put(string $key, MessageBagContract $bag): static
    {
        $this->bags[$key] = $bag;

        return $this;
    }

    /**
     * Determine if the default message bag has any messages.
     */
    public function any(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get the number of messages in the default bag.
     */
    public function count(): int
    {
        return $this->getBag('default')->count();
    }

    /**
     * Dynamically call methods on the default bag.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->getBag('default')->$method(...$parameters);
    }

    /**
     * Dynamically access a view error bag.
     *
     * @param  string  $key
     * @return MessageBagContract
     */
    public function __get($key)
    {
        return $this->getBag($key);
    }

    /**
     * Dynamically set a view error bag.
     *
     * @param  string  $key
     * @param  MessageBagContract  $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->put($key, $value);
    }

    /**
     * Convert the default bag to its string representation.
     */
    public function __toString(): string
    {
        return (string) $this->getBag('default');
    }
}
