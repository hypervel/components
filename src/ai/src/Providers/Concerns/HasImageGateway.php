<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\ImageGateway;

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
     * Boot or tests only for configured providers, which are shared across requests.
     */
    public function useImageGateway(ImageGateway $gateway): self
    {
        $this->imageGateway = $gateway;

        return $this;
    }
}
