<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Providers;

use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\StreamableAgentResponse;

interface TextProvider extends Provider
{
    /**
     * Invoke the given agent.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse;

    /**
     * Stream the response from the given agent.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse;

    /**
     * Set the provider's text gateway.
     */
    public function useTextGateway(StepTextGateway $gateway): self;

    /**
     * Get the multi-step text generation loop wrapping the provider's text gateway.
     */
    public function textGenerationLoop(): TextGenerationLoop;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string;

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string;

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string;
}
