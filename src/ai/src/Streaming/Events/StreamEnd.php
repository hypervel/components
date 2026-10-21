<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Support\Collection;

class StreamEnd extends StreamEvent
{
    /**
     * Create a stream end event.
     *
     * @param Collection<int, Step> $steps replay state for the completed turn; never serialized to clients
     */
    public function __construct(
        public string $id,
        public string $reason,
        public TextUsage $usage,
        public int $timestamp,
        public Collection $steps = new Collection,
    ) {
    }

    /**
     * Combine the stream end usages in the given collection of events into a single usage instance.
     */
    public static function combineUsage(Collection|array $events): TextUsage
    {
        $events = is_array($events) ? new Collection($events) : $events;

        return $events->whereInstanceOf(StreamEnd::class)
            ->values()
            ->map(fn (StreamEnd $event): TextUsage => $event->usage)
            ->reduce(fn (TextUsage $total, TextUsage $usage): TextUsage => $total->add($usage), new TextUsage);
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
            'type' => 'stream_end',
            'reason' => $this->reason,
            'usage' => $this->usage->toArray(),
            'timestamp' => $this->timestamp,
        ];
    }
}
