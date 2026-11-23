<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\TranscriptionGateway;
use Hypervel\Support\Attributes\BootOrTestsOnly;

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
     * Changing a shared provider's gateway affects subsequent operations.
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function useTranscriptionGateway(TranscriptionGateway $gateway): self
    {
        $this->transcriptionGateway = $gateway;

        return $this;
    }
}
