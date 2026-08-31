<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Arr;
use Hypervel\Telescope\Telescope;

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
    abstract public function register(Application $app): void;

    /**
     * Set the watcher options.
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Hide the given parameters.
     */
    protected function hideParameters(array $data, array $hidden): array
    {
        foreach ($hidden as $parameter) {
            if (Arr::has($data, $parameter)) {
                Arr::set($data, $parameter, Telescope::REDACTED_VALUE);
            }
        }

        return $data;
    }
}
