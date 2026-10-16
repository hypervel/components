<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Gateway\TextGenerationLoop;

trait HasTextGateway
{
    protected StepTextGateway $textGateway;

    protected ?TextGenerationLoop $textGenerationLoop = null;

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ?? $this->gateway;
    }

    /**
     * Set the provider's text gateway.
     *
     * Boot or tests only for configured providers, which are shared across requests.
     */
    public function useTextGateway(StepTextGateway $gateway): self
    {
        $this->textGateway = $gateway;
        $this->textGenerationLoop = null;

        return $this;
    }

    /**
     * Get the multi-step text generation loop wrapping the provider's text gateway.
     */
    public function textGenerationLoop(): TextGenerationLoop
    {
        return $this->textGenerationLoop ??= new TextGenerationLoop($this->textGateway());
    }
}
