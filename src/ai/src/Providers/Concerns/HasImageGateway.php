<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\ImageGateway;
use Hypervel\Support\Attributes\BootOrTestsOnly;

trait HasImageGateway
{
    protected ImageGateway $imageGateway;

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ?? $this->gateway;
    }

    /**
     * Set the provider's image gateway.
     *
     * Changing a shared provider's gateway affects subsequent operations.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function useImageGateway(ImageGateway $gateway): self
    {
        $this->imageGateway = $gateway;

        return $this;
    }
}
