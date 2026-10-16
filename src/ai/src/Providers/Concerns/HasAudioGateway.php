<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\AudioGateway;

trait HasAudioGateway
{
    protected AudioGateway $audioGateway;

    /**
     * Get the provider's audio gateway.
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ?? $this->gateway;
    }

    /**
     * Set the provider's audio gateway.
     *
     * Boot or tests only for configured providers, which are shared across requests.
     */
    public function useAudioGateway(AudioGateway $gateway): self
    {
        $this->audioGateway = $gateway;

        return $this;
    }
}
