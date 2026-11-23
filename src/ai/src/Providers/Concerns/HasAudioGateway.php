<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\AudioGateway;
use Hypervel\Support\Attributes\BootOrTestsOnly;

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
     * Changing a shared provider's gateway affects subsequent operations.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function useAudioGateway(AudioGateway $gateway): self
    {
        $this->audioGateway = $gateway;

        return $this;
    }
}
