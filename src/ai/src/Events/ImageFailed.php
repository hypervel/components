<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Prompts\ImagePrompt;
use Throwable;

/**
 * Dispatched once per failed attempt, including attempts failover recovers from.
 */
class ImageFailed
{
    /**
     * Create an event for a failed image generation attempt.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ImagePrompt $prompt,
        public Throwable $exception,
    ) {
    }
}
