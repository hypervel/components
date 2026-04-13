<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Events;

class PublishingStubs
{
    use Dispatchable;

    /**
     * The stubs being published.
     */
    public array $stubs = [];

    /**
     * Create a new event instance.
     */
    public function __construct(array $stubs)
    {
        $this->stubs = $stubs;
    }

    /**
     * Add a new stub to be published.
     */
    public function add(string $path, string $name): static
    {
        $this->stubs[$path] = $name;

        return $this;
    }
}
