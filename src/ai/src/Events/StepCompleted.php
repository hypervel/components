<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\StepResponse;

class StepCompleted
{
    /**
     * Create an event for a completed generation step.
     *
     * @param string $model the model the step was requested against, which the responding model reported in $response->meta may differ from
     * @param float $time wall time spent in the provider call, in milliseconds
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public StepResponse $response,
        public float $time,
    ) {
    }
}
