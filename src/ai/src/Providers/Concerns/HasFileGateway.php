<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\FileGateway;

trait HasFileGateway
{
    protected FileGateway $fileGateway;

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway;
    }

    /**
     * Set the provider's file gateway.
     *
     * Boot or tests only for configured providers, which are shared across requests.
     */
    public function useFileGateway(FileGateway $gateway): self
    {
        $this->fileGateway = $gateway;

        return $this;
    }
}
