<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Support\Attributes\BootOrTestsOnly;

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
     * Changing a shared provider's gateway affects subsequent operations.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function useEmbeddingGateway(EmbeddingGateway $gateway): self
    {
        $this->embeddingGateway = $gateway;

        return $this;
    }
}
