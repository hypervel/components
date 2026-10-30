<?php

declare(strict_types=1);

namespace Hypervel\Queue\Outbox\Concerns;

use Hypervel\Contracts\Queue\Queue;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Queue\Outbox\OutboxBatchResult;
use Hypervel\Queue\Outbox\OutboxMessage;
use Hypervel\Support\InteractsWithTime;
use Throwable;

trait PublishesOutboxMessagesSequentially
{
    use InteractsWithTime;

    /**
     * Publish due raw messages and defer future messages without losing their delays.
     *
     * @param list<OutboxMessage> $messages
     */
    protected function publishOutboxMessagesSequentially(Queue $queue, array $messages): OutboxBatchResult
    {
        $due = [];
        $deferred = [];
        $accepted = [];
        $now = $this->currentTime();

        foreach ($messages as $message) {
            if ($message->deliverAt !== null && $message->deliverAt > $now) {
                $deferred[] = $message->id;
            } else {
                $due[] = $message;
            }
        }

        try {
            foreach ($due as $message) {
                $queue->pushRaw($message->payload, $message->queue, $message->options);
                $accepted[] = $message->id;
            }
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }

            return new OutboxBatchResult($accepted, $deferred, $exception);
        }

        return new OutboxBatchResult($accepted, $deferred);
    }
}
