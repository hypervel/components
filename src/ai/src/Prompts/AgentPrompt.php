<?php

declare(strict_types=1);

namespace Hypervel\Ai\Prompts;

use Closure;
use Hypervel\Ai\Approvals\ApprovalClaim;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Exceptions\FailoverableException;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Providers\Tools\ProviderTool;
use Hypervel\Ai\Support\PendingConversationTitle;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Throwable;

class AgentPrompt extends Prompt
{
    public readonly Agent $agent;

    public readonly Collection $attachments;

    /**
     * The ad-hoc message history to send ahead of the prompt.
     *
     * @var null|list<Message>
     */
    public readonly ?array $messages;

    /**
     * The tools available for this run, or null to use the tools the agent declares.
     *
     * @var null|array<int, Agent|ProviderTool|Tool>
     */
    public readonly ?array $tools;

    public readonly ?int $timeout;

    public readonly ?string $invocationId;

    public readonly ?string $parentInvocationId;

    public readonly ?string $parentToolInvocationId;

    protected readonly bool $isFinalAttempt;

    protected ?RunContext $runContext = null;

    protected bool $streaming = false;

    protected ?PendingConversationTitle $pendingConversationTitle = null;

    /** @var null|Closure(Closure): mixed */
    protected ?Closure $contextRunner;

    /**
     * Create an agent prompt.
     *
     * @param bool $isFinalAttempt whether the caller has run out of providers to retry this prompt against
     * @param null|Closure(Closure): mixed $contextRunner
     */
    public function __construct(
        Agent $agent,
        string $prompt,
        Collection|array $attachments,
        TextProvider $provider,
        string $model,
        ?int $timeout = null,
        ?string $invocationId = null,
        ?Decisions $approvalDecisions = null,
        ?string $parentInvocationId = null,
        ?string $parentToolInvocationId = null,
        bool $isFinalAttempt = true,
        ?array $messages = null,
        ?array $tools = null,
        ?Closure $contextRunner = null,
    ) {
        parent::__construct($prompt, $provider, $model, $approvalDecisions);

        $this->agent = $agent;
        $this->attachments = Collection::make($attachments);
        $this->messages = $messages;
        $this->tools = $tools;
        $this->timeout = $timeout;
        $this->invocationId = $invocationId;
        $this->parentInvocationId = $parentInvocationId;
        $this->parentToolInvocationId = $parentToolInvocationId;
        $this->isFinalAttempt = $isFinalAttempt;
        $this->contextRunner = $contextRunner;
    }

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->prompt, $string);
    }

    /**
     * Prepend to the prompt and return a new prompt instance.
     */
    public function prepend(string $prompt): AgentPrompt
    {
        return $this->revise($prompt . PHP_EOL . PHP_EOL . $this->prompt);
    }

    /**
     * Append to the prompt and return a new prompt instance.
     */
    public function append(string $prompt): AgentPrompt
    {
        return $this->revise($this->prompt . PHP_EOL . PHP_EOL . $prompt);
    }

    /**
     * Revise the prompt and return a new prompt instance.
     */
    public function revise(string $prompt, Collection|array|null $attachments = null): AgentPrompt
    {
        if ($this->hasApprovalDecisions()) {
            return $this;
        }

        if (is_array($attachments)) {
            $attachments = new Collection($attachments);
        }

        $revised = new self(
            $this->agent,
            $prompt,
            $attachments ?? $this->attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            $this->invocationId,
            $this->approvalDecisions,
            $this->parentInvocationId,
            $this->parentToolInvocationId,
            $this->isFinalAttempt,
            $this->messages,
            $this->tools,
            $this->contextRunner,
        );

        $revised->streaming = $this->streaming;

        return $revised;
    }

    /**
     * Replace the tools for this run, returning a new prompt instance.
     *
     * @param iterable<int, Agent|ProviderTool|Tool> $tools
     */
    public function withTools(iterable $tools): AgentPrompt
    {
        if ($this->hasApprovalDecisions()) {
            return $this;
        }

        $revised = new self(
            $this->agent,
            $this->prompt,
            $this->attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            $this->invocationId,
            $this->approvalDecisions,
            $this->parentInvocationId,
            $this->parentToolInvocationId,
            $this->isFinalAttempt,
            $this->messages,
            [...$tools],
            $this->contextRunner,
        );

        $revised->streaming = $this->streaming;

        return $revised;
    }

    /**
     * Add new attachment to the prompt, returning a new prompt instance.
     */
    public function withAttachments(Collection|array $attachments): AgentPrompt
    {
        return $this->revise($this->prompt, $attachments);
    }

    /**
     * Get the provider instance.
     */
    public function provider(): TextProvider
    {
        return $this->provider;
    }

    /**
     * Determine whether the caller has run out of providers to retry this prompt against.
     *
     * @internal
     */
    public function isFinalAttempt(): bool
    {
        return $this->isFinalAttempt;
    }

    /**
     * Mark this prompt for lazy streaming execution.
     *
     * @internal
     */
    public function markAsStreaming(): void
    {
        $this->streaming = true;
    }

    /**
     * Determine whether provider execution is deferred until stream consumption.
     *
     * @internal
     */
    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    /**
     * Attach the title request owned by the current attempt.
     *
     * @internal
     */
    public function setPendingConversationTitle(?PendingConversationTitle $title): void
    {
        $this->pendingConversationTitle = $title;
    }

    /**
     * Get the title request owned by the current attempt.
     *
     * @internal
     */
    public function pendingConversationTitle(): ?PendingConversationTitle
    {
        return $this->pendingConversationTitle;
    }

    /**
     * Set the context the run dispatched for this prompt records its steps on.
     *
     * @internal
     */
    public function setRunContext(?RunContext $context): void
    {
        $this->runContext = $context;
    }

    /**
     * Get the context the run dispatched for this prompt is recording its steps on.
     *
     * @internal
     */
    public function runContext(): ?RunContext
    {
        return $this->runContext;
    }

    /**
     * Get the claim a conversation store must verify when settling this continuation.
     */
    public function approvalClaim(): ?ApprovalClaim
    {
        return $this->runContext?->approvalClaim();
    }

    /**
     * Get the captured operation context runner.
     *
     * @return null|Closure(Closure): mixed
     *
     * @internal
     */
    public function contextRunner(): ?Closure
    {
        return $this->contextRunner;
    }

    /**
     * Set the operation context captured by the streaming factory.
     *
     * @param null|Closure(Closure): mixed $runner
     *
     * @internal
     */
    public function setContextRunner(?Closure $runner): void
    {
        $this->contextRunner = $runner;
    }

    /**
     * Determine whether the caller will retry this prompt against another provider.
     *
     * @internal
     */
    public function willRetry(Throwable $exception): bool
    {
        return $exception instanceof FailoverableException && ! $this->isFinalAttempt;
    }

    /**
     * Prepare the prompt for serialization without its live execution state.
     */
    public function __serialize(): array
    {
        $properties = get_mangled_object_vars($this);

        unset($properties["\0*\0runContext"], $properties["\0*\0pendingConversationTitle"]);

        // Queued listeners restore their own context rather than capturing this operation.
        $properties["\0*\0contextRunner"] = null;

        return $properties;
    }
}
