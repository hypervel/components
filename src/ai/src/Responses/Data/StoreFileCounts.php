<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses\Data;

use Hypervel\Contracts\Support\Arrayable;
use JsonSerializable;

class StoreFileCounts implements Arrayable, JsonSerializable
{
    /**
     * Create store file counts.
     */
    public function __construct(
        public readonly int $completed,
        public readonly int $pending,
        public readonly int $failed,
    ) {
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'completed' => $this->completed,
            'pending' => $this->pending,
            'failed' => $this->failed,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
