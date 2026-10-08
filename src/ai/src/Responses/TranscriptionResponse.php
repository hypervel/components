<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TranscriptionSegment;
use Hypervel\Ai\Responses\Data\TranscriptionUsage;
use Hypervel\Support\Collection;
use Stringable;

class TranscriptionResponse implements Stringable
{
    public string $text;

    /** @var Collection<int, TranscriptionSegment> */
    public Collection $segments;

    public TranscriptionUsage $usage;

    public Meta $meta;

    /**
     * Create a transcription response.
     *
     * @param Collection<int, TranscriptionSegment> $segments
     */
    public function __construct(
        string $text,
        Collection $segments,
        TranscriptionUsage $usage,
        Meta $meta,
    ) {
        $this->text = $text;
        $this->segments = $segments;
        $this->usage = $usage;
        $this->meta = $meta;
    }

    /**
     * Get the string representation of the transcription.
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
