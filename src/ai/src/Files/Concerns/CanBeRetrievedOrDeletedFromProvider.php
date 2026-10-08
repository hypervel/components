<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files\Concerns;

use Hypervel\Ai\Files;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\FileResponse;

trait CanBeRetrievedOrDeletedFromProvider
{
    /**
     * Retrieve the file from a given provider.
     */
    public function get(Provider|string|null $provider = null): FileResponse
    {
        return Files::get($this->id, provider: $provider);
    }

    /**
     * Delete the file on a given provider.
     */
    public function delete(Provider|string|null $provider = null): void
    {
        Files::delete($this->id, provider: $provider);
    }
}
