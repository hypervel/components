<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Psr\Container\ContainerInterface;

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
    abstract public function register(ContainerInterface $app): void;

    /**
     * Set the watcher options.
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }
}
