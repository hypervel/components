<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\QueuedAgentResponse;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Broadcasting\Channel;
use Hypervel\Contracts\Container\Transient;
use Stringable;

interface Agent extends Transient
{
    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string;

    /**
     * Invoke the agent with a given prompt, or resume a paused run with tool approval decisions.
     */
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Provider|Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse;

    /**
     * Invoke the agent with a given prompt and return a streamable response.
     */
    public function stream(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Provider|Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse;

    /**
     * Invoke the agent in a queued job.
     */
    public function queue(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Provider|Lab|array|string|null $provider = null,
        ?string $model = null
    ): QueuedAgentResponse;

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcast(
        AgentInput|UserMessage|Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = true,
        Provider|Lab|array|string|null $provider = null,
        ?string $model = null
    ): StreamableAgentResponse;

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events immediately.
     */
    public function broadcastNow(
        AgentInput|UserMessage|Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Provider|Lab|array|string|null $provider = null,
        ?string $model = null
    ): StreamableAgentResponse;

    /**
     * Queue the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcastOnQueue(
        AgentInput|UserMessage|Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Provider|Lab|array|string|null $provider = null,
        ?string $model = null
    ): QueuedAgentResponse;
}
