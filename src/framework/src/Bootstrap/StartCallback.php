<?php

declare(strict_types=1);

namespace Hypervel\Framework\Bootstrap;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Framework\Events\OnStart;
use Swoole\Server as SwooleServer;

class StartCallback
{
    public function __construct(protected Dispatcher $dispatcher)
    {
    }

    /**
     * Handle the server start event.
     */
    public function onStart(SwooleServer $server): void
    {
        $this->dispatcher->dispatch(new OnStart($server));
    }
}
