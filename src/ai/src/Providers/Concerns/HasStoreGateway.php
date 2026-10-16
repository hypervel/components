<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\StoreGateway;

trait HasStoreGateway
{
    protected StoreGateway $storeGateway;

    /**
     * Get the provider's store gateway.
     */
    public function storeGateway(): StoreGateway
    {
        return $this->storeGateway;
    }

    /**
     * Set the provider's store gateway.
     *
     * Boot or tests only for configured providers, which are shared across requests.
     */
    public function useStoreGateway(StoreGateway $gateway): self
    {
        $this->storeGateway = $gateway;

        return $this;
    }
}
