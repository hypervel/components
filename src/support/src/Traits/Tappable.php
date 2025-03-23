<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use function Hyperf\Tappable\tap;

trait Tappable
{
    /**
     * Call the given Closure with this instance then return the instance.
     *
     * @param null|(callable($this): mixed) $callback
     * @param null|mixed $callback
     * @return ($callback is null ? \Hyperf\Tappable\HigherOrderTapProxy : $this)
     */
    public function tap($callback = null)
    {
        return tap($this, $callback);
    }
}
