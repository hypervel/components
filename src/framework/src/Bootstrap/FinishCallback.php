<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Framework\Events\OnFinish;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server;

class FinishCallback
{
    public function __construct(protected EventDispatcherInterface $dispatcher)
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
