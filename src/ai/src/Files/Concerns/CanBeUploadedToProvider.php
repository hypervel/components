<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files\Concerns;

use Hypervel\Ai\Files;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\StoredFileResponse;

trait CanBeUploadedToProvider
{
    /**
     * Store the file on a given provider.
     */
    public function put(?string $mimeType = null, ?string $name = null, Provider|string|null $provider = null): StoredFileResponse
    {
        return Files::put(
            $this,
            mimeType: $mimeType ?? $this->mimeType(),
            name: $name ?? $this->name(),
            provider: $provider
        );
    }
}
