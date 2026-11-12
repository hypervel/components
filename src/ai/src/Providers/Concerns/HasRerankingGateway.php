<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\RerankingGateway;
use Hypervel\Support\Attributes\BootOrTestsOnly;

trait HasRerankingGateway
{
    protected RerankingGateway $rerankingGateway;

    /**
     * Get the provider's reranking gateway.
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway;
    }

    /**
     * Set the provider's reranking gateway.
     *
     * Changing a shared provider's gateway affects subsequent operations.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function useRerankingGateway(RerankingGateway $gateway): self
    {
        $this->rerankingGateway = $gateway;

        return $this;
    }
}
