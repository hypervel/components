<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

final readonly class MetricsResponsePipeMessage
{
    /**
     * Create a new metrics response pipe message.
     */
    public function __construct(
        public string $requestId,
        public array $payload,
    ) {
    }
}
