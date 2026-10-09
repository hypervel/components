<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;

trait HasEmbeddingGateway
{
    protected EmbeddingGateway $embeddingGateway;

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ?? $this->gateway;
    }

    /**
     * Set the provider's embedding gateway.
     *
     * Boot or tests only for configured providers, which are shared across requests.
     */
    public function useEmbeddingGateway(EmbeddingGateway $gateway): self
    {
        $this->embeddingGateway = $gateway;

        return $this;
    }
}
