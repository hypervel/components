<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Throwable;

class StepFailed
{
    /**
     * Create an event for a failed generation step.
     *
     * @param Throwable $exception a StreamErrorException carries the provider's own error event on ->error
     * @param float $time wall time spent in the provider call before it failed, in milliseconds
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public Throwable $exception,
        public float $time,
    ) {
    }
}
