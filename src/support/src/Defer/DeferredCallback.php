<?php

declare(strict_types=1);

namespace Hypervel\Support\Defer;

use Closure;
use Hypervel\Support\Str;

class DeferredCallback
{
    /**
     * The deferred callback.
     */
    public Closure $callback;

    /**
     * Create a new deferred callback instance.
     */
    public function __construct(
        callable $callback,
        public ?string $name = null,
        public bool $always = false
    ) {
        $this->callback = Closure::fromCallable($callback);
        $this->name = $name ?? (string) Str::uuid();
    }

    /**
     * Specify the callback name.
     */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Indicate that the callback should run on unsuccessful requests and jobs.
     */
    public function always(bool $always = true): static
    {
        $this->always = $always;

        return $this;
    }

    /**
     * Invoke the deferred callback.
     */
    public function __invoke(): void
    {
        ($this->callback)();
    }
}
