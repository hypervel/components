<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

trait Dumpable
{
    /**
     * Dump the given arguments and terminate execution.
     */
    public function dd(mixed ...$args): never
    {
        dd($this, ...$args);
    }

    /**
     * Dump the given arguments.
     */
    public function dump(mixed ...$args): static
    {
        dump($this, ...$args);

        return $this;
    }
}
