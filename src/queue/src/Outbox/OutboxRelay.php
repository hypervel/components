<?php

declare(strict_types=1);

namespace Hypervel\Queue\Outbox;

use Closure;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Factory;
use Hypervel\Contracts\Queue\PublishesOutboxMessages;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Coroutine\Waiter;
use Hypervel\Queue\Events\OutboxBatchDelivered;
use Hypervel\Queue\Events\OutboxBatchDeliveryFailed;
use Hypervel\Queue\Outbox\Concerns\PublishesOutboxMessagesSequentially;
use LogicException;
use RuntimeException;
use Throwable;

class OutboxRelay
{
    use PublishesOutboxMessagesSequentially;

    /**
     * Create a relay whose dependencies do not retain borrowed connections.
     */
    public function __construct(
        protected DatabaseOutboxRepository $repository,
        protected Factory $queues,
        protected Dispatcher $events,
        protected ExceptionHandler $exceptions,
    ) {
    }

    /**
     * Claim messages and release the source connection before returning.
     *
     * @return list<OutboxMessage>
     */
    public function claim(string $database, int $limit): array
    {
        return $this->inFreshCoroutine(fn (): array => $this->repository->claim($database, $limit));
    }

    /**
     * Publish and settle a claimed batch, returning whether it completed without failure.
     *
     * @param list<OutboxMessage> $messages
     */
    public function deliver(string $database, array $messages): bool
    {
        $groups = [];

        foreach ($messages as $message) {
            $groups[$message->connection][] = $message;
        }

        $outcomes = [];
        $successful = true;

        foreach ($groups as $group) {
            $connection = $group[0]->connection;
            $startedAt = hrtime(true);
            $publishedAt = $this->currentTime();
            $result = $this->inFreshCoroutine(function () use ($connection, $group): OutboxBatchResult {
                try {
                    $queue = $this->queues->connection($connection);

                    return $queue instanceof PublishesOutboxMessages
                        ? $queue->publishOutboxMessages($group)
                        : $this->publishOutboxMessagesSequentially($queue, $group);
                } catch (Throwable $exception) {
                    if ($exception instanceof ChildCancellationException || Coroutine::causedByCancellation($exception)) {
                        throw $exception;
                    }

                    return new OutboxBatchResult(failure: $exception);
                }
            });
            $duration = (hrtime(true) - $startedAt) / 1_000_000_000;
            $result = $this->validateResult($group, $result);
            $settlementFailure = $this->inFreshCoroutine(function () use ($database, $group, $result): ?Throwable {
                try {
                    if ($this->repository->settle($database, $group, $result) !== count($group)) {
                        return new RuntimeException('Outbox lease ownership changed before the batch could be fully settled.');
                    }

                    return null;
                } catch (Throwable $exception) {
                    if ($exception instanceof ChildCancellationException || Coroutine::causedByCancellation($exception)) {
                        throw $exception;
                    }

                    return $exception;
                }
            });

            $successful = $successful && $result->failure === null && $settlementFailure === null;
            $outcomes[] = [$connection, $group, $result, $settlementFailure, $duration, $publishedAt];
        }

        // Observers cannot prevent another connection's committed work from being delivered.
        foreach ($outcomes as [$connection, $group, $result, $settlementFailure, $duration, $publishedAt]) {
            $this->reportOutcome($database, $connection, $group, $result, $settlementFailure, $duration, $publishedAt);
        }

        return $successful;
    }

    /**
     * Reject driver results that cannot safely identify the rows to settle.
     *
     * @param non-empty-list<OutboxMessage> $messages
     */
    protected function validateResult(array $messages, OutboxBatchResult $result): OutboxBatchResult
    {
        $input = [];

        foreach ($messages as $message) {
            $input[$message->id] = $message;
        }

        $seen = [];
        $valid = true;

        foreach ([$result->accepted, $result->deferred] as $partition => $ids) {
            foreach ($ids as $id) {
                if (! is_int($id) || ! isset($input[$id]) || isset($seen[$id])
                    || ($partition === 1 && $input[$id]->deliverAt === null)) {
                    $valid = false;
                    break 2;
                }

                $seen[$id] = true;
            }
        }

        if (! $valid || ($result->failure === null && count($seen) !== count($messages))) {
            return new OutboxBatchResult(failure: new LogicException(
                'Outbox publication must return disjoint input row IDs, defer only messages with a delivery time, and account for every row unless publication failed.',
                previous: $result->failure,
            ));
        }

        return $result;
    }

    /**
     * Notify listeners and report failures after publication and settlement have released their resources.
     *
     * @param non-empty-list<OutboxMessage> $messages
     */
    protected function reportOutcome(
        string $database,
        string $connection,
        array $messages,
        OutboxBatchResult $result,
        ?Throwable $settlementFailure,
        float $duration,
        int $publishedAt,
    ): void {
        $failure = $result->failure ?? $settlementFailure;
        $attempted = count($messages) - count($result->deferred);
        $eventClass = $failure === null ? OutboxBatchDelivered::class : OutboxBatchDeliveryFailed::class;
        $notify = $attempted > 0 && $this->events->hasListeners($eventClass);

        if (! $notify && $failure === null) {
            return;
        }

        $this->inFreshCoroutine(function () use ($database, $connection, $messages, $result, $settlementFailure, $duration, $publishedAt, $failure, $attempted, $notify): void {
            $listenerFailure = null;

            if ($notify) {
                $eligibleAt = $publishedAt;
                $deferred = array_fill_keys($result->deferred, true);

                foreach ($messages as $message) {
                    if (! isset($deferred[$message->id])) {
                        $eligibleAt = min($eligibleAt, $message->eligibleAt);
                    }
                }

                $event = $failure === null
                    ? new OutboxBatchDelivered($database, $connection, count($result->accepted), $duration, $publishedAt - $eligibleAt)
                    : new OutboxBatchDeliveryFailed($database, $connection, count($result->accepted), $attempted - count($result->accepted), $duration, $publishedAt - $eligibleAt, $failure);

                try {
                    $this->events->dispatch($event);
                } catch (Throwable $exception) {
                    if ($exception instanceof ChildCancellationException || Coroutine::causedByCancellation($exception)) {
                        throw $exception;
                    }

                    $listenerFailure = $exception;
                }
            }

            if ($failure !== null || $listenerFailure !== null) {
                $context = [
                    'outbox_database' => $database,
                    'queue_connection' => $connection,
                    'outbox_ids' => array_column($messages, 'id'),
                    'job_uuids' => array_column($messages, 'uuid'),
                    'jobs' => array_map(static function (OutboxMessage $message): mixed {
                        $payload = json_decode($message->payload, true);

                        return $payload['displayName'] ?? $payload['job'] ?? null;
                    }, $messages),
                ];

                foreach ([$result->failure, $settlementFailure, $listenerFailure] as $exception) {
                    if ($exception !== null) {
                        $this->exceptions->report($exception, $context);
                    }
                }
            }
        });
    }

    /**
     * Finish an operation's resource cleanup before returning or propagating cancellation.
     *
     * @template TReturn
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    protected function inFreshCoroutine(Closure $callback): mixed
    {
        return (new Waiter(-1))->wait($callback, waitForChildTermination: true);
    }
}
