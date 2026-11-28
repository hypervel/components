<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Concerns;

use Hypervel\Server\Commands\ServerRecycleWorkersCommand;

trait RecyclesWorkers
{
    /**
     * Request a best-effort recycle of the local server workers.
     */
    protected function recycleWorkers(): void
    {
        $this->callSilently(ServerRecycleWorkersCommand::class);
    }
}
