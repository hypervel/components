<?php

declare(strict_types=1);

namespace Hypervel\Queue\Concerns;

use DateInterval;
use DateTimeInterface;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Queue\ShouldQueueViaOutbox;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Outbox\OutboxDispatcher;
use Hypervel\Support\Str;
use JsonException;
use LogicException;
use Swoole\Coroutine\CanceledException;
use Throwable;

trait StagesOutboxJobs
{
    protected const string OUTBOX_FORWARDED_CONTEXT_KEY = '__queue.outbox.forwarded';

    /**
     * Whether this connection stages jobs through the outbox by default.
     */
    protected bool $dispatchViaOutbox = false;

    /**
     * Determine whether the job selects outbox delivery.
     */
    protected function shouldDispatchViaOutbox(object|string $job): bool
    {
        $selected = $job->viaOutbox ?? ($job instanceof ShouldQueueViaOutbox || $this->dispatchViaOutbox);

        return $selected && (CoroutineContext::get(self::OUTBOX_FORWARDED_CONTEXT_KEY)['job'] ?? null) !== $job;
    }

    /**
     * Run a child attempt or handler with its own forwarding context.
     *
     * @param null|array{job: object|string, afterCommitHandled: bool} $forwarded
     */
    protected function withOutboxForwarding(?array $forwarded, callable $callback): mixed
    {
        $previous = CoroutineContext::get(self::OUTBOX_FORWARDED_CONTEXT_KEY);

        if ($previous === $forwarded) {
            return $callback();
        }

        $forwarded === null
            ? CoroutineContext::forget(self::OUTBOX_FORWARDED_CONTEXT_KEY)
            : CoroutineContext::set(self::OUTBOX_FORWARDED_CONTEXT_KEY, $forwarded);

        try {
            return $callback();
        } finally {
            $previous === null
                ? CoroutineContext::forget(self::OUTBOX_FORWARDED_CONTEXT_KEY)
                : CoroutineContext::set(self::OUTBOX_FORWARDED_CONTEXT_KEY, $previous);
        }
    }

    /**
     * Determine whether this driver needs SQL staging for the selected job.
     */
    protected function shouldStageViaOutbox(object|string $job): bool
    {
        return $this->shouldDispatchViaOutbox($job);
    }

    /**
     * Select an explicit transaction for a local or failover after-commit fallback.
     */
    protected function resolveOutboxCommitConnection(DatabaseTransactionsManager $transactions, object|string $job): ?string
    {
        if (! $this->shouldDispatchViaOutbox($job) || ($database = $job->outboxDatabase ?? null) === null) {
            return null;
        }

        /** @var ConnectionResolverInterface $resolver */
        $resolver = $this->container->make('db');
        $owner = $resolver->connection($database)->getNameWithReadWriteType() ?? '';
        $active = $transactions->callbackApplicableTransactions();

        if ($active->isNotEmpty() && ! $transactions->hasApplicableTransaction($owner)) {
            throw new LogicException("Outbox database [{$database}] does not own an active transaction.");
        }

        return $owner;
    }

    /**
     * Stage a single job through its normal guarded enqueue lifecycle.
     */
    protected function enqueueViaOutbox(object|string $job, string $payload, ?string $queue, DateInterval|DateTimeInterface|int|null $delay): string
    {
        try {
            $dispatcher = $this->container->make(OutboxDispatcher::class);
            $connection = $dispatcher->databaseFor($job);
            $payload = $this->finalizePayloadForQueueing($queue, $job, $payload, $delay);
            $row = $this->prepareOutboxRow($job, $payload, $queue, $delay);
            $this->raiseJobQueueingEvent($queue, $job, $payload, $delay);
            $dispatcher->store($connection, [$row], [$job]);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->raiseJobQueueingFailedEvent($queue, $job, $payload, $delay, $exception);

            throw $exception;
        }

        $this->acceptDispatchLocks($job);
        $this->raiseJobQueuedEvent($queue, $row['uuid'], $job, $payload, $delay);

        return $row['uuid'];
    }

    /**
     * Stage selected jobs in owner groups and return the direct remainder.
     */
    protected function stageOutboxJobs(array $jobs, mixed $data, ?string $queue): array
    {
        $direct = $jobs;
        $selected = [];

        foreach ($jobs as $index => $job) {
            if ($this->shouldStageViaOutbox($job)) {
                $selected[] = $job;
                unset($direct[$index]);
            }
        }

        if ($selected === []) {
            return $jobs;
        }

        $dispatcher = $this->container->make(OutboxDispatcher::class);
        $prepared = $this->prepareBatchJobs($selected, $data, $queue);
        $groups = [];

        try {
            // Resolve every owner before finalization or insertion of any group.
            foreach ($prepared as $index => $member) {
                $connection = $dispatcher->databaseFor($member['job']);
                $name = $connection->getNameWithReadWriteType() ?? '';
                $groups[$name]['connection'] = $connection;
                $groups[$name]['members'][] = $index;
            }

            foreach ($groups as $group) {
                $rows = [];
                $groupJobs = [];

                foreach ($group['members'] as $index) {
                    $member = &$prepared[$index];
                    $member['payload'] = $this->finalizePayloadForQueueing($queue, $member['job'], $member['payload'], $member['delay']);
                    $rows[] = $this->prepareOutboxRow($member['job'], $member['payload'], $queue, $member['delay']);
                    $groupJobs[] = $member['job'];
                    unset($member);
                }

                foreach ($group['members'] as $index) {
                    $member = $prepared[$index];
                    $this->raiseJobQueueingEvent($queue, $member['job'], $member['payload'], $member['delay']);
                }

                $dispatcher->store($group['connection'], $rows, $groupJobs);
                $accepted = [];

                foreach ($group['members'] as $position => $index) {
                    $member = $prepared[$index];
                    $this->acceptDispatchLocks($member['job']);
                    $member['uuid'] = $rows[$position]['uuid'];
                    $accepted[] = $member;
                    unset($prepared[$index]);
                }

                $failure = $this->notifyBatchMembers($accepted, function (array $member) use ($queue): void {
                    $this->raiseJobQueuedEvent($queue, $member['uuid'], $member['job'], $member['payload'], $member['delay']);
                });

                if ($failure !== null) {
                    throw $failure;
                }
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $failure = $this->notifyBatchMembers($prepared, function (array $member) use ($queue, $exception): void {
                $this->raiseJobQueueingFailedEvent($queue, $member['job'], $member['payload'], $member['delay'], $exception);
            });

            throw $failure ?? $exception;
        }

        return array_values($direct);
    }

    /**
     * Build a byte-preserving row from a finalized payload.
     */
    protected function prepareOutboxRow(object|string $job, string $payload, ?string $queue, DateInterval|DateTimeInterface|int|null $delay): array
    {
        $storedPayload = $this->preparePayloadForOutbox($job, $payload);

        try {
            $decoded = json_decode($storedPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidPayloadException('Unable to decode the outbox payload: ' . $exception->getMessage(), $storedPayload);
        }

        if (! is_array($decoded) || ! is_string($decoded['uuid'] ?? null) || ! Str::isUuid($decoded['uuid'])) {
            throw new InvalidPayloadException('The outbox payload must contain a valid job UUID.', $storedPayload);
        }

        $queue = $this->resolveOutboxQueue($queue);
        $now = $this->currentTime();
        // Even an already-due timer overrides SQS's queue-level delay.
        $deliverAt = empty($delay) ? null : max($this->availableAt($delay), $now);
        $options = $this->prepareOutboxOptions($job, $queue, $storedPayload);

        return [
            'uuid' => $decoded['uuid'],
            'connection' => $this->connectionName,
            'queue' => $queue,
            'payload' => $storedPayload,
            'options' => $options === [] ? null : json_encode($options, JSON_THROW_ON_ERROR),
            'deliver_at' => $deliverAt,
            'attempts' => 0,
            'available_at' => $this->availableAtForOutbox($queue, $deliverAt),
            'lease_token' => null,
            'last_error' => null,
            'created_at' => $now,
        ];
    }

    /**
     * Expand the logical destination without applying publication-time forwarding.
     */
    protected function resolveOutboxQueue(?string $queue): ?string
    {
        return $queue;
    }

    /**
     * Add metadata required while the original job is still available.
     */
    protected function preparePayloadForOutbox(object|string $job, string $payload): string
    {
        return $payload;
    }

    /**
     * Capture transport options that cannot be recovered from the stored envelope.
     */
    protected function prepareOutboxOptions(object|string $job, ?string $queue, string $payload): array
    {
        return [];
    }

    /**
     * Determine when a stored message may first be published.
     */
    protected function availableAtForOutbox(?string $queue, ?int $deliverAt): int
    {
        return $deliverAt ?? $this->currentTime();
    }
}
