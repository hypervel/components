<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

final readonly class MetricsRequestPipeMessage
{
    /**
     * Create a new metrics request pipe message.
     */
    public function __construct(
        public string $requestId,
        public string $appId,
        public string $metricType,
        public array $options,
    ) {
    }
}
