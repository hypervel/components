<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Support\Attributes\BootOrTestsOnly;

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
     * Changing a shared provider's gateway affects subsequent operations.
     */
    #[BootOrTestsOnly(whenShared: true)]
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
