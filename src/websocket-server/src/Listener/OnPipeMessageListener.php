<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Listener;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Event\Contracts\ListenerInterface;
use Hypervel\ExceptionHandler\Formatter\FormatterInterface;
use Hypervel\Framework\Events\OnPipeMessage;
use Hypervel\WebSocketServer\Sender;
use Hypervel\WebSocketServer\SenderPipeMessage;
use Throwable;

class OnPipeMessageListener implements ListenerInterface
{
    public function __construct(private Container $container, private StdoutLoggerInterface $logger, private Sender $sender)
    {
    }

    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array
    {
        return [
            OnPipeMessage::class,
        ];
    }

    /**
     * Handle the Event when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     */
    public function process(object $event): void
    {
        if ($event instanceof OnPipeMessage && $event->data instanceof SenderPipeMessage) {
            /** @var SenderPipeMessage $message */
            $message = $event->data;

            try {
                [$fd, $method] = $this->sender->getFdAndMethodFromProxyMethod($message->name, $message->arguments);
                $this->sender->proxy($fd, $method, $message->arguments);
            } catch (Throwable $exception) {
                $formatter = $this->container->make(FormatterInterface::class);
                $this->logger->warning($formatter->format($exception));
            }
        }
    }
}
