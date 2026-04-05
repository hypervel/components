<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Listeners;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Core\Events\OnPipeMessage;
use Hypervel\WebSocketServer\Sender;
use Hypervel\WebSocketServer\SenderPipeMessage;
use Throwable;

class OnPipeMessageListener
{
    public function __construct(
        private StdoutLoggerInterface $logger,
        private Sender $sender,
    ) {
    }

    /**
     * Handle a pipe message event for WebSocket sender messages.
     */
    public function handle(OnPipeMessage $event): void
    {
        if ($event->data instanceof SenderPipeMessage) {
            /** @var SenderPipeMessage $message */
            $message = $event->data;

            try {
                [$fd, $method] = $this->sender->getFdAndMethodFromProxyMethod($message->name, $message->arguments);
                $this->sender->proxy($fd, $method, $message->arguments);
            } catch (Throwable $exception) {
                $this->logger->warning((string) $exception);
            }
        }
    }
}
