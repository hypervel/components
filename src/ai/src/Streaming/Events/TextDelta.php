<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

use Hypervel\Support\Collection;

class TextDelta extends StreamEvent
{
    /**
     * Create a text delta event.
     */
    public function __construct(
        public string $id,
        public string $messageId,
        public string $delta,
        public int $timestamp,
    ) {
    }

    /**
     * Combine the text deltas, starting a new paragraph only after a step that ran tools.
     */
    public static function combine(Collection|array $events): string
    {
        return static::join(Collection::wrap($events)
            ->chunkWhile(fn (StreamEvent $event, int|string $key, Collection $step): bool => ! $event instanceof StreamStart
                || $step->doesntContain(fn (StreamEvent $previous): bool => $previous instanceof ToolResult))
            ->map(fn (Collection $step): string => $step->whereInstanceOf(TextDelta::class)
                ->map(fn (TextDelta $event): string => $event->delta)
                ->join('')));
    }

    /**
     * Join the text of each step, separating the steps with a blank line.
     *
     * @param array<array-key, string>|Collection<array-key, string> $texts
     */
    public static function join(Collection|array $texts): string
    {
        return Collection::wrap($texts)
            ->filter(fn (string $text): bool => trim($text) !== '')
            ->join("\n\n");
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
            'type' => 'text_delta',
            'message_id' => $this->messageId,
            'delta' => $this->delta,
            'timestamp' => $this->timestamp,
        ];
    }
}
