<?php

declare(strict_types=1);

namespace Hypervel\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnFinish;
use Swoole\Server;

class FinishCallback
{
    public function __construct(protected Dispatcher $dispatcher)
    {
    }

    /**
     * Handle the task finish event.
     */
    public function onFinish(Server $server, int $taskId, mixed $data): void
    {
        $this->dispatcher->dispatch(new OnFinish($server, $taskId, $data));
    }
}
