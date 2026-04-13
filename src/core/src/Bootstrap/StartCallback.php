<?php

declare(strict_types=1);

namespace Hypervel\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\OnStart;
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
