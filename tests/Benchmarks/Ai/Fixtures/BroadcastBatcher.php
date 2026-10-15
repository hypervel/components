<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\Ai\Fixtures;

use Closure;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Engine\Channel;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

/**
 * Benchmark-only batching candidate for the AI port plan, not a production API.
 */
class BroadcastBatcher
{
    private const int MAX_ENCODED_TEXT_BYTES = 4096;

    /**
     * Run the producer while one joined sender coalesces adjacent text deltas from a bounded channel.
     *
     * @param Closure(Closure): mixed $produce
     * @param Closure(StreamEvent, list<string>): void $send
     */
    public static function run(Closure $produce, Closure $send, int $milliseconds): void
    {
        $channel = new Channel(128);

        parallel([
            static function () use ($channel, $send, $milliseconds): void {
                try {
                    self::drain($channel, $send, $milliseconds);
                } finally {
                    // A failed sender must also wake a producer blocked on a full channel.
                    $channel->close();
                }
            },
            static function () use ($channel, $produce): void {
                try {
                    $produce(static function (StreamEvent $event) use ($channel): void {
                        if (! $channel->push([$event, hrtime(true)])) {
                            throw new RuntimeException('The broadcast benchmark sender closed before all events were queued.');
                        }
                    });
                } finally {
                    $channel->close();
                }
            },
        ]);
    }

    /**
     * Send compatible queued text without extending the first arrival's deadline.
     *
     * @param Channel<array{StreamEvent, int}> $channel
     * @param Closure(StreamEvent, list<string>): void $send
     */
    private static function drain(Channel $channel, Closure $send, int $milliseconds): void
    {
        $pending = null;

        while (($item = $pending ?? $channel->pop()) !== false) {
            $pending = null;
            [$first, $arrived] = $item;
            if ($first::class !== TextDelta::class) {
                $send($first, [$first->id]);
                continue;
            }

            $text = $first->delta;
            $ids = [$first->id];
            $bytes = strlen(json_encode($text, JSON_THROW_ON_ERROR)) - 2;
            $deadline = $arrived + $milliseconds * 1_000_000;

            while ($bytes < self::MAX_ENCODED_TEXT_BYTES) {
                $remaining = $deadline - hrtime(true);
                if ($channel->isEmpty() && $remaining <= 0) {
                    break;
                }

                // pop(0) waits indefinitely; only a nonempty channel can be drained without a timer.
                $next = $channel->isEmpty() ? $channel->pop($remaining / 1e9) : $channel->pop();
                if ($next === false) {
                    break;
                }

                [$event] = $next;
                if ($event::class !== TextDelta::class || $event->messageId !== $first->messageId || $event->invocationId !== $first->invocationId) {
                    $pending = $next;
                    break;
                }

                $additional = strlen(json_encode($event->delta, JSON_THROW_ON_ERROR)) - 2;
                if ($bytes + $additional > self::MAX_ENCODED_TEXT_BYTES) {
                    $pending = $next;
                    break;
                }

                $text .= $event->delta;
                $ids[] = $event->id;
                $bytes += $additional;
            }

            $batch = new TextDelta($first->id, $first->messageId, $text, $first->timestamp);
            $batch->invocationId = $first->invocationId;
            $send($batch, $ids);
        }
    }
}
