<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\StoreGateway;
use Hypervel\Support\Attributes\BootOrTestsOnly;

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
     * Changing a shared provider's gateway affects subsequent operations.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function useStoreGateway(StoreGateway $gateway): self
    {
        $this->storeGateway = $gateway;

        return $this;
    }
}
