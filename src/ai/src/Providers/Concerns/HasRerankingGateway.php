<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\RerankingGateway;

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
     * Boot or tests only for configured providers, which are shared across requests.
     */
    public function useRerankingGateway(RerankingGateway $gateway): self
    {
        $this->rerankingGateway = $gateway;

        return $this;
    }
}
