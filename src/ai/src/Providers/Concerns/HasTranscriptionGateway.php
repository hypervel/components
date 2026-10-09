<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\TranscriptionGateway;

trait HasTranscriptionGateway
{
    protected TranscriptionGateway $transcriptionGateway;

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ?? $this->gateway;
    }

    /**
     * Set the provider's transcription gateway.
     *
     * Boot or tests only for configured providers, which are shared across requests.
     */
    public function useTranscriptionGateway(TranscriptionGateway $gateway): self
    {
        $this->transcriptionGateway = $gateway;

        return $this;
    }
}
