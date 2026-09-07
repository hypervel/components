<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use OpenTelemetry\Context\ContextInterface;

final readonly class ExceptionContext
{
    /**
     * Create an exception-context handoff.
     */
    public function __construct(
        public ContextInterface $context,
        public ?string $origin,
    ) {
    }
}
