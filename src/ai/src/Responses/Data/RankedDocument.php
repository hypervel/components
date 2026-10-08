<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

use Hypervel\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

class RankedDocument implements Arrayable, JsonSerializable, Stringable
{
    /**
     * Create a new ranked document instance.
     */
    public function __construct(
        public readonly int $index,
        public readonly string $document,
        public readonly float $score,
    ) {
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'document' => $this->document,
            'score' => $this->score,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the document content.
     */
    public function __toString(): string
    {
        return $this->document;
    }
}
