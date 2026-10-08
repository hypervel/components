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
     * Combine the text deltas in the given collection of events into a single string.
     *
     * Each step of a multi-step generation is a self-contained utterance
     * (typically narration around a tool call), so steps are joined with a blank
     * line instead of being run together mid-sentence. The boundary is the step's
     * own `StreamStart` rather than a change of message ID, which Anthropic rotates
     * per content block — web search splits one answer across several, mid-sentence.
     */
    public static function combine(Collection|array $events): string
    {
        return Collection::wrap($events)
            ->chunkWhile(fn (StreamEvent $event): bool => ! $event instanceof StreamStart)
            ->map(fn (Collection $step): string => $step->whereInstanceOf(TextDelta::class)
                ->map(fn (TextDelta $event): string => $event->delta)
                ->join(''))
            ->filter(fn (string $text): bool => trim($text) !== '')
            ->values()
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
