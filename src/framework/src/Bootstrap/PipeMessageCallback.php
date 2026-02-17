<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Framework\Events\OnPipeMessage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server as SwooleServer;

class PipeMessageCallback
{
    public function __construct(protected EventDispatcherInterface $dispatcher)
    {
    }

    /**
     * Handle the pipe message event.
     */
    public function onPipeMessage(SwooleServer $server, int $fromWorkerId, mixed $data): void
    {
        $this->dispatcher->dispatch(new OnPipeMessage($server, $fromWorkerId, $data));
    }
}
