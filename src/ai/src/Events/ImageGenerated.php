<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Prompts\ImagePrompt;
use Hypervel\Ai\Responses\ImageResponse;

class ImageGenerated
{
    /**
     * Create an event for generated images.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ImagePrompt $prompt,
        public ImageResponse $response,
    ) {
    }
}
