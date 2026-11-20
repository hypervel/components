<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

class StepContext
{
    /**
     * Create the context for a generation step.
     *
     * @param null|string $continuationToken provider handle the previous step returned for the next one, such as a response or container id
     */
    public function __construct(
        public readonly int $stepNumber = 0,
        public readonly bool $isFinalStep = false,
        public readonly ?string $continuationToken = null,
    ) {
    }
}
