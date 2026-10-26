<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming;

use Closure;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Engine\Channel;
use Hypervel\Log\Context\Repository as ContextRepository;
use InvalidArgumentException;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Own the sender while generation and its database transactions stay in the caller.
 */
class BroadcastBatcher
{
    /** @var Channel<array{StreamEvent, int}> */
    protected Channel $channel;

    protected WaitConcurrent $concurrent;

    protected ?Throwable $failure = null;

    /**
     * Start one sender in the operation's captured context.
     *
     * @param Closure(StreamEvent): void $send
     * @param null|Closure(Closure): mixed $runner
     */
    public function __construct(
        Closure $send,
        ?Closure $runner,
        protected int $milliseconds,
        protected int $maxBytes,
    ) {
        if ($milliseconds < 0 || $maxBytes < 1) {
            throw new InvalidArgumentException('Broadcast batching requires a nonnegative window and a positive byte limit.');
        }

        $this->channel = new Channel(128);
        $this->concurrent = new WaitConcurrent(1);
        $this->concurrent->fork(function () use ($send, $runner): void {
            try {
                $work = fn () => $this->drain($send);
                $runner === null ? $work() : $runner($work);
            } catch (Throwable $exception) {
                $this->failure = Coroutine::isCancellation($exception)
                    ? new ChildCancellationException('The broadcast sender was canceled while its owner remained active.', previous: $exception)
                    : $exception;
            } finally {
                // Wake a producer blocked on a full channel if the sender fails.
                $this->channel->close();
            }
        }, [ContextRepository::CONTEXT_KEY, ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY]);
    }

    /**
     * Queue an original event without allowing an unbounded backlog.
     */
    public function push(StreamEvent $event): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $arrived = hrtime(true);

        if ($this->channel->isFull()) {
            // The sender may need this pool slot to queue the events we are waiting on.
            ConnectionResolver::releaseIdleConnections();
        }

        if (! $this->channel->push([$event, $arrived])) {
            if ($this->channel->isCanceled()) {
                throw new CanceledException('Waiting to broadcast an agent event was canceled.');
            }

            throw $this->failure ?? new RuntimeException('The broadcast sender stopped before all events were queued.');
        }
    }

    /**
     * Drain accepted events and wait for the sender to finish.
     */
    public function finish(): void
    {
        $this->channel->close();

        try {
            // Completion callbacks may have borrowed the slot needed to send the final batches.
            ConnectionResolver::releaseIdleConnections();
        } catch (CanceledException $exception) {
            $this->cancel();

            throw $exception;
        } catch (Throwable $exception) {
            $this->concurrent->wait();

            throw $exception;
        }

        $this->concurrent->wait();

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    /**
     * Cancel the owned sender without draining after parent cancellation.
     */
    public function cancel(): void
    {
        $this->concurrent->cancel();
        $this->channel->close();
    }

    /**
     * Send compatible queued text without extending the first arrival's deadline.
     *
     * @param Closure(StreamEvent): void $send
     */
    protected function drain(Closure $send): void
    {
        $pending = null;

        while (($item = $pending ?? $this->read()) !== false) {
            $pending = null;
            [$first, $arrived] = $item;
            if (! $this->isBatchable($first)) {
                $send($first);
                continue;
            }

            $text = $first->delta;
            $bytes = strlen(json_encode($text, JSON_THROW_ON_ERROR)) - 2;
            $deadline = $arrived + $this->milliseconds * 1_000_000;

            while ($bytes < $this->maxBytes) {
                $next = $this->read($deadline);
                if ($next === false) {
                    break;
                }

                [$event] = $next;
                if (! $this->isBatchable($event) || ! $this->isCompatible($first, $event)) {
                    $pending = $next;
                    break;
                }

                $additional = strlen(json_encode($event->delta, JSON_THROW_ON_ERROR)) - 2;
                if ($bytes + $additional > $this->maxBytes) {
                    $pending = $next;
                    break;
                }

                $text .= $event->delta;
                $bytes += $additional;
            }

            $batch = clone $first;
            $batch->delta = $text;
            $send($batch);
        }
    }

    /**
     * Read an available event or wait until the current batch's deadline.
     *
     * @return array{StreamEvent, int}|false
     */
    protected function read(?int $deadline = null): array|false
    {
        $timeout = -1.0;

        if ($this->channel->isEmpty() && ! $this->channel->isClosing()) {
            if ($deadline !== null && $deadline <= hrtime(true)) {
                return false;
            }

            // The parent may need this sender's idle queue connection to persist the turn.
            ConnectionResolver::releaseIdleConnections();

            if ($deadline !== null) {
                $timeout = ($deadline - hrtime(true)) / 1e9;

                // pop(0) waits indefinitely; an overdue batch must flush instead.
                if ($timeout <= 0) {
                    return false;
                }
            }
        }

        $event = $this->channel->pop($timeout);

        if ($event === false && $this->channel->isCanceled()) {
            throw new CanceledException('Waiting for an agent broadcast event was canceled.');
        }

        return $event;
    }

    /**
     * Identify plain deltas without additional event semantics.
     *
     * @phpstan-assert-if-true TextDelta|ReasoningDelta $event
     */
    protected function isBatchable(StreamEvent $event): bool
    {
        return $event::class === TextDelta::class
            || ($event::class === ReasoningDelta::class && $event->summary === null);
    }

    /**
     * Match the invocation and content block of adjacent deltas.
     */
    protected function isCompatible(TextDelta|ReasoningDelta $first, TextDelta|ReasoningDelta $event): bool
    {
        return match (true) {
            $first instanceof TextDelta && $event instanceof TextDelta => $first->invocationId === $event->invocationId && $first->messageId === $event->messageId,
            $first instanceof ReasoningDelta && $event instanceof ReasoningDelta => $first->invocationId === $event->invocationId && $first->reasoningId === $event->reasoningId,
            default => false,
        };
    }
}
