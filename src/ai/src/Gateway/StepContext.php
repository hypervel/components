<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

class StepContext
{
    /**
     * Create the context for a generation step.
     *
     * @param null|string $continuationToken provider handle for stateful continuation; null for stateless providers that replay full history
     */
    public function __construct(
        public readonly int $stepNumber = 0,
        public readonly bool $isFinalStep = false,
        public readonly ?string $continuationToken = null,
    ) {
    }
}
