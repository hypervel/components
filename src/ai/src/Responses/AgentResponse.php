<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Support\Collection;

class AgentResponse extends TextResponse
{
    public string $invocationId;

    public ?string $conversationId = null;

    public ?object $conversationUser = null;

    public ?string $userMessageId = null;

    public ?string $assistantMessageId = null;

    /**
     * Create an agent response.
     */
    public function __construct(string $invocationId, string $text, TextUsage $usage, Meta $meta)
    {
        $this->invocationId = $invocationId;

        parent::__construct($text, $usage, $meta);
    }

    /**
     * Create a fake response that reasoned before answering.
     */
    public static function fakeWithReasoning(string $reasoning, string $text = ''): self
    {
        return tap(new self('fake-invocation', $text, new TextUsage, new Meta), function (self $response) use ($reasoning): void {
            $response->reasoning = $reasoning;
        });
    }

    /**
     * Create a fake response that is waiting for approval.
     *
     * @param array<int, PendingApproval>|Collection<int, PendingApproval> $pendingApprovals
     */
    public static function fakeWithPendingApprovals(array|Collection $pendingApprovals): self
    {
        return (new self('fake-invocation', '', new TextUsage, new Meta))
            ->withPendingApprovals(Collection::make($pendingApprovals));
    }

    /**
     * Set the conversation UUID and participant for this response.
     */
    public function withinConversation(string $conversationId, ?object $conversationUser = null): static
    {
        $this->conversationId = $conversationId;
        $this->conversationUser = $conversationUser;

        return $this;
    }

    /**
     * Set the conversation message rows this turn wrote.
     */
    public function withStoredMessages(?string $userMessageId, ?string $assistantMessageId): static
    {
        $this->userMessageId = $userMessageId;
        $this->assistantMessageId = $assistantMessageId;

        return $this;
    }

    /**
     * Execute a callback with this response.
     */
    public function then(callable $callback): static
    {
        $callback($this);

        return $this;
    }
}
