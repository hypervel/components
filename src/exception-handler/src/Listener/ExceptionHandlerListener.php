<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler\Listener;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Framework\Events\BootApplication;
use Hypervel\Support\SplPriorityQueue;

class ExceptionHandlerListener
{
    public const HANDLER_KEY = 'exceptions.handler';

    /**
     * Create a new exception handler listener instance.
     */
    public function __construct(private Repository $config)
    {
    }

    /**
     * Register and prioritize exception handlers from config.
     */
    public function handle(BootApplication $event): void
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
