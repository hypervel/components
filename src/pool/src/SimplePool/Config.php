<?php

declare(strict_types=1);

namespace Hypervel\Pool\SimplePool;

/**
 * Configuration for a simple pool.
 */
class Config
{
    /**
     * @var callable
     */
    protected $callback;

    /**
     * @param array<string, mixed> $option
     */
    public function __construct(
        protected string $name,
        callable $callback,
        protected array $option
    ) {
        $this->callback = $callback;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the pool name.
     *
     * Boot-only. The value persists on the worker-lifetime SimplePool config
     * and is captured by the Pool on its first resolution. Per-request use
     * races across coroutines.
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * Set the connection-creation callback.
     *
     * Boot-only. The callback persists on the worker-lifetime SimplePool
     * config and is captured by the Pool on its first resolution. Per-request
     * use races across coroutines.
     */
    public function setCallback(callable $callback): static
    {
        $this->callback = $callback;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOption(): array
    {
        return $this->option;
    }

    /**
     * Set the pool option array.
     *
     * Boot-only. The value persists on the worker-lifetime SimplePool config
     * and is captured by the Pool on its first resolution. Per-request use
     * races across coroutines.
     *
     * @param array<string, mixed> $option
     */
    public function setOption(array $option): static
    {
        $this->option = $option;

        return $this;
    }
}
