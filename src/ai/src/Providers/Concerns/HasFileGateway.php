<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\FileGateway;
use Hypervel\Support\Attributes\BootOrTestsOnly;

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
     * Changing a shared provider's gateway affects subsequent operations.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function useFileGateway(FileGateway $gateway): self
    {
        $this->fileGateway = $gateway;

        return $this;
    }
}
