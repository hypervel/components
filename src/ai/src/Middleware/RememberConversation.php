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
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Storage\StoredTurn;
use Hypervel\Ai\Support\PendingConversationTitle;
use Hypervel\Support\ClassMetadataCache;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Swoole\Coroutine\CanceledException;
use Throwable;

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

        $pendingConversationId = $agent->currentConversation() === null
            ? (string) Str::uuid7()
            : null;

        $title = null;

        try {
            if (! $prompt->isStreaming()) {
                $prompt->setPendingConversationTitle($title = $this->startConcurrentTitle($prompt, $pendingConversationId));
            }

            try {
                $response = $next($prompt);
            } catch (Throwable $exception) {
                $this->rememberFailedTurn($prompt, $exception, $pendingConversationId);

                throw $exception;
            }

            // Record each failed producer attempt; public catch callbacks run only once per response.
            if ($response instanceof StreamableAgentResponse) {
                $response->wrapProducer(function (Closure $produce) use ($prompt, $agent, &$pendingConversationId, $response): Generator {
                    $title = null;
                    $completed = false;

                    try {
                        $prompt->setPendingConversationTitle($title = $this->startConcurrentTitle($prompt, $pendingConversationId));

                        try {
                            yield from $produce();
                        } catch (Throwable $exception) {
                            $stored = $this->rememberFailedTurn($prompt, $exception, $pendingConversationId, retryable: ! $response->hasYielded());

                            if ($stored !== null) {
                                $pendingConversationId = null;
                                $response->withinConversation($stored->conversationId, $agent->conversationParticipant());
                            }

                            throw $exception;
                        }

                        $title?->value();
                        $completed = true;
                    } finally {
                        $title?->cancel();

                        // Completion callbacks run after the producer ends and still need its title.
                        if (! $completed) {
                            $prompt->setPendingConversationTitle(null);
                        }
                    }
                });
            }

            // Surface the ID to stream protocols without treating it as an existing conversation...
            if ($pendingConversationId !== null && $response instanceof StreamableAgentResponse) {
                $response->withinConversation($pendingConversationId, $agent->conversationParticipant());
            }

            return $response->then(function (AgentResponse $completedResponse) use ($prompt, $agent, &$pendingConversationId): void {
                try {
                    if (! $this->shouldRemember($agent, $prompt, $completedResponse)) {
                        if ($pendingConversationId !== null) {
                            $completedResponse->conversationId = null;
                            $completedResponse->conversationUser = null;
                        }

                        return;
                    }

                    $stored = $this->rememberTurn($prompt, $completedResponse, $pendingConversationId);
                    $pendingConversationId = null;

                    $completedResponse->withinConversation(
                        $stored->conversationId,
                        $agent->conversationParticipant(),
                    )->withStoredMessages($stored->userMessageId, $stored->assistantMessageId);
                } finally {
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
     *
     * Return the stored IDs so a re-iterated stream does not recreate its pending conversation.
     */
    protected function rememberFailedTurn(AgentPrompt $prompt, Throwable $exception, ?string $pendingConversationId, bool $retryable = true): ?StoredTurn
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        // A failover retry writes the turn itself, so only the attempt the caller gives up on is recorded...
        if ($retryable && $prompt->willRetry($exception)) {
            return null;
        }

        $context = $prompt->runContext();

        try {
            if ($context === null || $context->hasUnclaimedApprovals() || ! $this->shouldRememberTurn($agent, $prompt)) {
                return null;
            }

            $response = $context->recordedResponse();

            // A resume that died before its first step still has to fail the row its approvals were written to...
            if ($response->steps->isEmpty() && $context->approvalClaim() === null) {
                return null;
            }

            return $this->rememberTurn($prompt, $response, $pendingConversationId, $exception);
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
        $participant = $agent->conversationParticipant();
        [$participantType, $participantId] = $this->participantKeys($participant);
        $newConversation = $this->startsConversation($agent, $pendingConversationId);
        $conversationId = $newConversation ? ($pendingConversationId ?? (string) Str::uuid7()) : $agent->currentConversation();
        $title = $newConversation ? ($prompt->pendingConversationTitle()?->value() ?? $this->generateTitle($prompt->prompt)) : null;

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
            $agent->continue($stored->conversationId, $participant);
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
        return $agent->hasConversationParticipant()
            || $agent->currentConversation() !== null
            || $prompt->hasApprovalDecisions();
    }

    /**
     * Determine whether this attempt needs to create a conversation.
     *
     * @param Agent&RemembersConversations $agent
     */
    protected function startsConversation(Agent $agent, ?string $pendingConversationId): bool
    {
        return $pendingConversationId !== null || ! $agent->currentConversation();
    }

    /**
     * Start an eligible title request alongside the main generation.
     */
    protected function startConcurrentTitle(AgentPrompt $prompt, ?string $pendingConversationId): ?PendingConversationTitle
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        // Decisions are validated during generation, and agent fakes hand out responses in call order.
        if (! config()->boolean('ai.conversations.concurrent_title', false)
            || ! config()->boolean('ai.conversations.generate_title', true)
            || $prompt->hasApprovalDecisions()
            || ! $this->startsConversation($agent, $pendingConversationId)
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
