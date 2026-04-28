<?php

declare(strict_types=1);

namespace Hypervel\Testing\Fluent\Concerns;

use Hypervel\Support\Traits\Dumpable;

trait Debugging
{
    use Dumpable;

    /**
     * Dump the given props.
     */
    public function dump(?string $prop = null): static
    {
        dump($this->prop($prop));

        return $this;
    }

    /**
     * Retrieve a prop within the current scope using "dot" notation.
     */
    abstract protected function prop(?string $key = null): mixed;
}
