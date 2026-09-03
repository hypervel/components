<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

final readonly class ProviderSet
{
    /**
     * Create a set of worker-local SDK providers.
     */
    public function __construct(
        public ?MeterProviderInterface $metrics,
        public ?TracerProviderInterface $traces,
        public ?LoggerProviderInterface $logs,
    ) {
    }
}
