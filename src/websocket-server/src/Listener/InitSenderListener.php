<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Listener;

use Hypervel\Contracts\Container\Container;
use Hypervel\Event\Contracts\ListenerInterface;
use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\WebSocketServer\Sender;

class InitSenderListener implements ListenerInterface
{
    public function __construct(private Container $container)
    {
    }

    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array
    {
        return [
            AfterWorkerStart::class,
        ];
    }

    public function process(object $event): void
    {
        if ($this->container->has(Sender::class)) {
            $sender = $this->container->make(Sender::class);
            $sender->setWorkerId($event->workerId);
        }
    }
}
