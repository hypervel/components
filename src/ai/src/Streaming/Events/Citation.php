<?php

declare(strict_types=1);

namespace Hypervel\Ai\Streaming\Events;

use Hypervel\Ai\Responses\Data\Citation as CitationData;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Support\Collection;
use UnhandledMatchError;

class Citation extends StreamEvent
{
    /**
     * Create a citation event.
     */
    public function __construct(
        public string $id,
        public string $messageId,
        public CitationData $citation,
        public int $timestamp,
    ) {
    }

    /**
     * Combine citation events into the sources the run cited, in the order it cited them.
     *
     * @return Collection<int, CitationData>
     */
    public static function combine(Collection|array $events): Collection
    {
        return Collection::wrap($events)
            ->whereInstanceOf(Citation::class)
            ->map(fn (Citation $event): CitationData => $event->citation)
            ->values();
    }

    /**
     * Get the event as an array.
     *
     * @return array<string, mixed>
     *
     * @throws UnhandledMatchError when the citation has no supported event representation
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'citation',
            'message_id' => $this->messageId,
            'citation' => match (true) {
                $this->citation instanceof UrlCitation => [
                    'title' => $this->citation->title,
                    'url' => $this->citation->url,
                ],
            },
            'timestamp' => $this->timestamp,
        ];
    }
}
