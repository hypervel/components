<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

use Hypervel\Ai\Concerns\JoinsReasoning;
use Hypervel\Support\Collection;

class ReasoningDelta extends StreamEvent
{
    use JoinsReasoning;

    /**
     * Create a reasoning delta event.
     */
    public function __construct(
        public string $id,
        public string $reasoningId,
        public string $delta,
        public int $timestamp,
        public ?array $summary = null,
    ) {
    }

    /**
     * Combine reasoning deltas by block, separating each block with a blank line.
     */
    public static function combine(Collection|array $events): string
    {
        return static::joinReasoning(
            Collection::wrap($events)
                ->whereInstanceOf(ReasoningDelta::class)
                ->groupBy(fn (ReasoningDelta $event): string => $event->reasoningId)
                ->map(fn (Collection $deltas): string => $deltas->pluck('delta')->implode(''))
                ->values()
        );
    }

    /**
     * Get the event as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'reasoning_delta',
            'reasoning_id' => $this->reasoningId,
            'delta' => $this->delta,
            'timestamp' => $this->timestamp,
            'summary' => $this->summary,
        ];
    }
}
