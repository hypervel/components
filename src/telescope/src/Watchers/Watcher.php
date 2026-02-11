<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Container\Container;

abstract class Watcher
{
    /**
     * Create a new watcher instance.
     *
     * @param array $options the configured watcher options
     */
    public function __construct(
        public array $options = []
    ) {
    }

    /**
     * Register the watcher.
     */
    abstract public function register(Container $app): void;

    /**
     * Set the watcher options.
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }
}
