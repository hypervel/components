<?php

declare(strict_types=1);

namespace Hypervel\Http;

class StreamedEvent
{
    /**
     * Create a new streamed event instance.
     */
    public function __construct(
        public string $event,
        public mixed $data,
    ) {
    }
}
