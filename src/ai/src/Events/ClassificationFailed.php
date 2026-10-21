<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Prompts\ClassificationPrompt;
use Throwable;

/**
 * Dispatched once per failed attempt, including attempts failover recovers from.
 */
class ClassificationFailed
{
    /**
     * Create an event for a failed classification attempt.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ClassificationPrompt $prompt,
        public Throwable $exception,
    ) {
    }
}
