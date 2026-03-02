<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnPipeMessage;
use Swoole\Server as SwooleServer;

class PipeMessageCallback
{
    public function __construct(protected Dispatcher $dispatcher)
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
