<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Files;

interface HasProviderId
{
    /**
     * Get the provider ID for the stored file.
     */
    public function id(): string;
}
