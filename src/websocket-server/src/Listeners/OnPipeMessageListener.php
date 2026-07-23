<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Listeners;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
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
     * Handle a WebSocket sender pipe message.
     */
    public function handle(SenderPipeMessage $message): void
    {
        try {
            [$fd, $method] = $this->sender->getFdAndMethodFromProxyMethod($message->name, $message->arguments);
            $this->sender->proxy($fd, $method, $message->arguments);
        } catch (Throwable $exception) {
            $this->logger->warning((string) $exception);
        }
    }
}
