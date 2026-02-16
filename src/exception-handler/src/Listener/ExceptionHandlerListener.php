<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler\Listener;

use Hyperf\Di\Annotation\AnnotationCollector;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Event\Contracts\ListenerInterface;
use Hypervel\ExceptionHandler\Annotation\ExceptionHandler;
use Hypervel\Framework\Events\BootApplication;
use Hypervel\Support\SplPriorityQueue;

class ExceptionHandlerListener implements ListenerInterface
{
    public const HANDLER_KEY = 'exceptions.handler';

    /**
     * Create a new exception handler listener instance.
     */
    public function __construct(private Repository $config)
    {
    }

    /**
     * Get the events the listener should listen for.
     */
    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    /**
     * Handle the event.
     */
    public function process(object $event): void
    {
        $queue = new SplPriorityQueue();
        $handlers = $this->config->get(self::HANDLER_KEY, []);
        foreach ($handlers as $server => $items) {
            foreach ($items as $handler => $priority) {
                if (! is_numeric($priority)) {
                    $handler = $priority;
                    $priority = 0;
                }
                $queue->insert([$server, $handler], $priority);
            }
        }

        $annotations = AnnotationCollector::getClassesByAnnotation(ExceptionHandler::class);
        /**
         * @var string $handler
         * @var ExceptionHandler $annotation
         */
        foreach ($annotations as $handler => $annotation) {
            $queue->insert([$annotation->server, $handler], $annotation->priority);
        }

        $this->config->set(self::HANDLER_KEY, $this->formatExceptionHandlers($queue));
    }

    /**
     * Format the exception handlers from the priority queue into a grouped array.
     */
    protected function formatExceptionHandlers(SplPriorityQueue $queue): array
    {
        $result = [];
        foreach ($queue as $item) {
            [$server, $handler] = $item;
            $result[$server][] = $handler;
            $result[$server] = array_values(array_unique($result[$server]));
        }
        return $result;
    }
}
