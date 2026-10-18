<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Providers;

use BadMethodCallException;
use Closure;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Contracts\Events\Dispatcher;

class FakeStreamingProvider extends Provider implements TextProvider
{
    /**
     * Create a streaming provider with a custom response factory.
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
        protected Closure $streamFactory,
    ) {
    }

    /**
     * Stream the prompt using the response factory.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        return ($this->streamFactory)($this, $prompt);
    }

    /**
     * Reject non-streaming prompts.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        throw new BadMethodCallException('FakeStreamingProvider::prompt is not implemented.');
    }

    /**
     * Retain the custom response factory when a gateway is supplied.
     */
    public function useTextGateway(StepTextGateway $gateway): self
    {
        return $this;
    }

    /**
     * Reject access to a text generation loop.
     */
    public function textGenerationLoop(): TextGenerationLoop
    {
        throw new BadMethodCallException('FakeStreamingProvider::textGenerationLoop is not implemented.');
    }

    /**
     * Get the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'fake-model';
    }

    /**
     * Get the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'fake-model';
    }

    /**
     * Get the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'fake-model';
    }
}
