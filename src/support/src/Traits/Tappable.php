<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Hypervel\Support\HigherOrderTapProxy;

trait Tappable
{
    /**
     * Call the given Closure with this instance then return the instance.
     *
     * @param null|(callable($this): mixed) $callback
     * @return ($callback is null ? HigherOrderTapProxy<$this> : $this)
     */
    public function tap(?callable $callback = null): mixed
    {
        return tap($this, $callback);
    }
}
