<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\ImagePrompt;
use Hypervel\Ai\Providers\Provider;

class GeneratingImage
{
    /**
     * Create an event for an image generation request.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ImagePrompt $prompt,
    ) {
    }
}
