<?php

declare(strict_types=1);

namespace Hypervel\Ai\Middleware;

use Closure;
use Generator;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\RemembersConversations;
use Hypervel\Ai\Contracts\StoresConversationTurns;
use Hypervel\Ai\Exceptions\StreamAbandonedException;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Storage\StoredTurn;
use Hypervel\Ai\Support\ConversationSelection;
use Hypervel\Ai\Support\PendingConversationTitle;
use Hypervel\Support\ClassMetadataCache;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Swoole\Coroutine\CanceledException;
use Throwable;
use WeakReference;

class RememberConversation
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ConversationStore $store,
        protected TextProvider $provider,
    ) {
    }

    /**
     * Determine whether the given agent remembers its conversations.
     */
    public static function appliesTo(Agent $agent): bool
    {
        return $agent instanceof RemembersConversations
            || ClassMetadataCache::usesTrait($agent::class, RemembersConversationsTrait::class);
    }

    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next): AgentResponse|StreamableAgentResponse
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        $prompt->captureConversation();

        /** @var ConversationSelection $selection */
        $selection = $prompt->conversationSelection();

        $pendingConversationId = $selection->conversationId === null
            ? (string) Str::uuid7()
            : null;

        $title = null;

        try {
            if (! $prompt->isStreaming()) {
                $prompt->setPendingConversationTitle($title = $this->startConcurrentTitle($prompt));
            }

            try {
                $response = $next($prompt);
            } catch (Throwable $exception) {
                $this->rememberFailedTurn($prompt, $exception, $pendingConversationId);

                throw $exception;
            }

            // Record each failed producer attempt; public catch callbacks run only once per response.
            if ($response instanceof StreamableAgentResponse) {
                // Use a weak reference so the producer does not keep its own response alive.
                $responseReference = WeakReference::create($response);
                $response->wrapProducer(function (Closure $produce) use ($prompt, $selection, $pendingConversationId, $responseReference): Generator {
                    $title = null;
                    $completed = false;
                    $threw = false;

                    try {
                        $prompt->setPendingConversationTitle($title = $this->startConcurrentTitle($prompt));

                        try {
                            yield from $produce();
                        } catch (Throwable $exception) {
                            $threw = true;
                            $this->rememberFailedTurn($prompt, $exception, $pendingConversationId, retryable: ! $responseReference->get()->hasYielded());

                            throw $exception;
                        }

                        $completed = true;
                    } finally {
                        try {
                            // Disposing a suspended producer skips catch, but executed tools and claims still need settlement.
                            if (! $completed && ! $threw) {
                                $this->rememberFailedTurn($prompt, new StreamAbandonedException('The stream was abandoned before it completed.'), $pendingConversationId, retryable: false);
                            }
                        } finally {
                            if ($selection->conversationId) {
                                $responseReference->get()?->withinConversation($selection->conversationId, $selection->participant);
                            }

                            // Completion callbacks run after the producer ends and still need its title.
                            if (! $completed) {
                                $title?->cancel();
                                $prompt->setPendingConversationTitle(null);
                            }
                        }
                    }
                });
            }

            // Surface the ID to stream protocols without treating it as an existing conversation...
            if ($pendingConversationId !== null && $response instanceof StreamableAgentResponse) {
                $response->withinConversation($pendingConversationId, $selection->participant);
            }

            return $response->then(function (AgentResponse $completedResponse) use ($prompt, $agent, $pendingConversationId): void {
                try {
                    if (! $this->shouldRemember($agent, $prompt, $completedResponse)) {
                        if ($pendingConversationId !== null) {
                            $completedResponse->conversationId = null;
                            $completedResponse->conversationUser = null;
                        }

                        return;
                    }

                    $this->rememberTurn($prompt, $completedResponse, $pendingConversationId);
                } finally {
                    $prompt->pendingConversationTitle()?->cancel();
                    $prompt->setPendingConversationTitle(null);
                }
            });
        } finally {
            if (! $prompt->isStreaming()) {
                $title?->cancel();
                $prompt->setPendingConversationTitle(null);
            }
        }
    }

    /**
     * Record the steps a run completed before it died, so the tools it already ran are not lost with it.
     */
    protected function rememberFailedTurn(AgentPrompt $prompt, Throwable $exception, ?string $pendingConversationId, bool $retryable = true): void
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        // A failover retry writes the turn itself, so only the attempt the caller gives up on is recorded...
        if ($retryable && $prompt->willRetry($exception)) {
            return;
        }

        $context = $prompt->runContext();

        try {
            if ($context === null || $context->hasUnclaimedApprovals() || ! $this->shouldRememberTurn($agent, $prompt)) {
                return;
            }

            $response = $context->recordedResponse();

            // A resume that died before its first step still has to fail the row its approvals were written to...
            if ($response->steps->isEmpty() && $context->approvalClaim() === null) {
                return;
            }

            $this->rememberTurn($prompt, $response, $pendingConversationId, $exception);
        } finally {
            // The store needs the claim held by this context until settlement finishes.
            $prompt->setRunContext(null);
        }
    }

    // REMOVED: openTurn() wrote half a turn before assistant persistence. rememberTurn() owns the complete write.

    /**
     * Store the complete turn and apply its conversation ID after persistence succeeds.
     */
    protected function rememberTurn(AgentPrompt $prompt, AgentResponse $response, ?string $pendingConversationId, ?Throwable $exception = null): StoredTurn
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;
        /** @var ConversationSelection $selection */
        $selection = $prompt->conversationSelection();
        $participant = $selection->participant;
        [$participantType, $participantId] = $this->participantKeys($participant);
        $newConversation = $this->startsConversation($prompt);
        $conversationId = $newConversation ? ($pendingConversationId ?? (string) Str::uuid7()) : $selection->conversationId;
        $title = null;
        $cancellation = null;

        if ($newConversation) {
            if (! $exception instanceof CanceledException && ! $exception instanceof StreamAbandonedException) {
                try {
                    $title = $prompt->pendingConversationTitle()?->value() ?? $this->generateTitle($prompt->prompt);
                } catch (CanceledException $caught) {
                    $cancellation = $caught;
                }
            }

            if ($title === null) {
                // Canceling title work must not discard the turn that already finished.
                $prompt->pendingConversationTitle()?->cancel();
                $title = $prompt->pendingConversationTitle()?->valueIfReady() ?? Str::limit($prompt->prompt, 50, preserveWords: true);
            }
        }

        if ($this->store instanceof StoresConversationTurns) {
            $stored = $this->store->storeTurn($conversationId, $title, $participantType, $participantId, $prompt, $response, $exception);
        } else {
            if ($newConversation) {
                $conversationId = $this->store->storeConversation(
                    $participantType,
                    $participantId,
                    $title,
                    $conversationId,
                );
            }

            // A resume continues the turn its decisions answer, so it adds no message of its own...
            $userMessageId = $prompt->hasApprovalDecisions() ? null : $this->store->storeUserMessage(
                $conversationId,
                $participantType,
                $participantId,
                $agent::class,
                new UserMessage($prompt->prompt, $prompt->attachments),
            );

            $assistantMessageId = $this->store->storeAssistantMessage(
                $conversationId,
                $participantType,
                $participantId,
                $prompt,
                $response,
                $exception,
            );

            $stored = new StoredTurn($conversationId, $userMessageId, $assistantMessageId);
        }

        if ($newConversation) {
            // Completing a lazy operation must not replace a selection the caller changed meanwhile.
            if ($agent->currentConversation() === $selection->conversationId && $agent->conversationParticipant() === $participant) {
                $agent->continue($stored->conversationId, $participant);
            }

            $selection->conversationId = $stored->conversationId;
        }

        $response->withinConversation($stored->conversationId, $participant)
            ->withStoredMessages($stored->userMessageId, $stored->assistantMessageId);

        if ($cancellation !== null) {
            throw $cancellation;
        }

        return $stored;
    }

    /**
     * Split a participant into the type and key it is stored under.
     *
     * @return array{?string, null|int|string}
     */
    protected function participantKeys(?object $participant): array
    {
        return $participant === null
            ? [null, null]
            : [Conversation::participantType($participant), Conversation::participantKey($participant)];
    }

    /**
     * Determine whether this turn should be persisted.
     *
     * @param Agent&RemembersConversations $agent
     */
    protected function shouldRemember(Agent $agent, AgentPrompt $prompt, AgentResponse $response): bool
    {
        return $this->shouldRememberTurn($agent, $prompt)
            || $response->hasPendingApprovals();
    }

    /**
     * Determine whether this turn belongs to a conversation, however it ended.
     *
     * @param Agent&RemembersConversations $agent
     */
    protected function shouldRememberTurn(Agent $agent, AgentPrompt $prompt): bool
    {
        /** @var ConversationSelection $selection */
        $selection = $prompt->conversationSelection();

        return $selection->participant !== null
            || $selection->conversationId !== null
            || $prompt->hasApprovalDecisions();
    }

    /**
     * Determine whether this attempt needs to create a conversation.
     */
    protected function startsConversation(AgentPrompt $prompt): bool
    {
        /** @var ConversationSelection $selection */
        $selection = $prompt->conversationSelection();

        return ! $selection->conversationId;
    }

    /**
     * Start an eligible title request alongside the main generation.
     */
    protected function startConcurrentTitle(AgentPrompt $prompt): ?PendingConversationTitle
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        // Decisions are validated during generation, and agent fakes hand out responses in call order.
        if (! config()->boolean('ai.conversations.concurrent_title', false)
            || ! config()->boolean('ai.conversations.generate_title', true)
            || $prompt->hasApprovalDecisions()
            || ! $this->startsConversation($prompt)
            || ! $this->shouldRememberTurn($agent, $prompt)
            || Ai::hasFakeGatewayFor($agent::class)) {
            return null;
        }

        DB::releaseIdleConnections();

        return new PendingConversationTitle(
            fn (): string => $this->generateTitle($prompt->prompt),
            $prompt->contextRunner() ?? Ai::captureContext(),
        );
    }

    /**
     * Generate a title for the conversation.
     */
    protected function generateTitle(string $prompt): string
    {
        if (! config()->boolean('ai.conversations.generate_title', true)) {
            return Str::limit($prompt, 50, preserveWords: true);
        }

        try {
            $response = $this->provider->textGenerationLoop()->generate(
                $this->provider,
                $this->provider->cheapestTextModel(),
                'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.',
                [new UserMessage(Str::limit($prompt, 500))],
            );

            return Str::limit($response->text, 100);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return Str::limit($prompt, 100, preserveWords: true);
        }
    }
}
