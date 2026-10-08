<?php

declare(strict_types=1);

namespace Hypervel\Ai\Concerns;

use Closure;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Gateway\FakeTextGateway;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\QueuedAgentPrompt;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeAgents
{
    /**
     * All of the registered fake agent gateways.
     */
    protected array $fakeAgentGateways = [];

    /**
     * All of the recorded agent prompts.
     */
    protected array $recordedPrompts = [];

    /**
     * All of the recorded agent prompts that were queued.
     */
    protected array $recordedQueuedPrompts = [];

    /**
     * Fake the responses returned by the given agent.
     *
     * Tests only. The fake gateway is shared by all requests in the worker.
     */
    public function fakeAgent(string $agent, Closure|array $responses = []): FakeTextGateway
    {
        return tap(
            new FakeTextGateway($responses),
            fn (FakeTextGateway $gateway): FakeTextGateway => $this->fakeAgentGateways[$agent] = $gateway
        );
    }

    /**
     * Determine if the given agent has been faked.
     */
    public function hasFakeGatewayFor(Agent|string $agent): bool
    {
        return array_key_exists(
            is_object($agent) ? $agent::class : $agent,
            $this->fakeAgentGateways
        );
    }

    /**
     * Get a fake gateway instance for the given agent.
     */
    public function fakeGatewayFor(Agent $agent): FakeTextGateway
    {
        return $this->hasFakeGatewayFor($agent)
            ? $this->fakeAgentGateways[$agent::class]
            : throw new InvalidArgumentException('Agent [' . $agent::class . '] has not been faked.');
    }

    /**
     * Record the given prompt for the faked agent.
     *
     * Tests only. Recorded prompts remain on the worker-shared manager.
     */
    public function recordPrompt(AgentPrompt|QueuedAgentPrompt $prompt): self
    {
        if ($prompt instanceof QueuedAgentPrompt) {
            $this->recordedQueuedPrompts[$prompt->agent::class][] = $prompt;
        } else {
            $this->recordedPrompts[$prompt->agent::class][] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that a prompt was received matching a given truth test.
     */
    public function assertAgentWasPrompted(
        string $agent,
        Closure|string $callback,
        ?array $prompts = null,
        ?string $message = null
    ): self {
        $callback = is_string($callback)
            ? fn (AgentPrompt|QueuedAgentPrompt $prompt): bool => $prompt->prompt === $callback
            : $callback;

        PHPUnit::assertTrue(
            (new Collection($prompts ?? $this->recordedPrompts[$agent] ?? []))->contains(fn (AgentPrompt|QueuedAgentPrompt $prompt): mixed => $callback($prompt)),
            $message ?? 'An expected prompt was not received.'
        );

        return $this;
    }

    /**
     * Assert that a certain number of prompts were received.
     */
    public function assertAgentWasPromptedTimes(string $agent, int $times = 1): self
    {
        $count = count($this->recordedPrompts[$agent] ?? []);

        PHPUnit::assertSame(
            $times,
            $count,
            sprintf(
                "Received {$count} %s instead of {$times} %s.",
                Str::plural('prompt', $count),
                Str::plural('prompt', $times),
            ),
        );

        return $this;
    }

    /**
     * Assert that a prompt was received matching a given truth test.
     */
    public function assertAgentWasQueued(string $agent, Closure|string $callback): self
    {
        return $this->assertAgentWasPrompted(
            $agent,
            $callback,
            $this->recordedQueuedPrompts[$agent] ?? [],
            'An expected queued prompt was not received.'
        );
    }

    /**
     * Assert that a prompt was not received matching a given truth test.
     */
    public function assertAgentNotPrompted(
        string $agent,
        Closure|string $callback,
        ?array $prompts = null,
        ?string $message = null
    ): self {
        $callback = is_string($callback)
            ? fn (AgentPrompt|QueuedAgentPrompt $prompt): bool => $prompt->prompt === $callback
            : $callback;

        PHPUnit::assertTrue(
            (new Collection($prompts ?? $this->recordedPrompts[$agent] ?? []))->doesntContain(fn (AgentPrompt|QueuedAgentPrompt $prompt): mixed => $callback($prompt)),
            $message ?? 'An unexpected prompt was received.'
        );

        return $this;
    }

    /**
     * Assert that a queued prompt was not received matching a given truth test.
     */
    public function assertAgentNotQueued(string $agent, Closure|string $callback): self
    {
        return $this->assertAgentNotPrompted(
            $agent,
            $callback,
            $this->recordedQueuedPrompts[$agent] ?? [],
            'An unexpected queued prompt was received.'
        );
    }

    /**
     * Assert that no prompts were received.
     */
    public function assertAgentNeverPrompted(string $agent): self
    {
        PHPUnit::assertEmpty(
            $this->recordedPrompts[$agent] ?? [],
            'An unexpected prompt was received.'
        );

        return $this;
    }

    /**
     * Assert that no queued prompts were received.
     */
    public function assertAgentNeverQueued(string $agent): self
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedPrompts[$agent] ?? [],
            'An unexpected queued prompt was received.'
        );

        return $this;
    }
}
