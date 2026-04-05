<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Listeners;

use Hypervel\Contracts\Container\Container;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\WebSocketServer\Sender;

class InitSenderListener
{
    public function __construct(private Container $container)
    {
    }

    /**
     * Initialize the WebSocket sender with the current worker ID.
     */
    public function handle(AfterWorkerStart $event): void
    {
        if ($this->container->has(Sender::class)) {
            $sender = $this->container->make(Sender::class);
            $sender->setWorkerId($event->workerId);
        }
    }
}
