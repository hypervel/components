<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bus;

use Closure;

class PendingClosureDispatch extends PendingDispatch
{
    /**
     * Add a callback to be executed if the job fails.
     */
    public function catch(Closure $callback): static
    {
        $this->job->onFailure($callback);

        return $this;
    }
}
