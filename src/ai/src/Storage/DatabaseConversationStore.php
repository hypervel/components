<?php

declare(strict_types=1);

namespace Hypervel\Ai\Storage;

use Hypervel\Ai\Approvals\ApprovalClaim;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\ClaimsPendingApprovals;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Contracts\PaginatesConversations;
use Hypervel\Ai\Contracts\ResolvesPendingApprovals;
use Hypervel\Ai\Contracts\StoresConversationTurns;
use Hypervel\Ai\Contracts\VerifiesConversationOwnership;
use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Models\ConversationMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\ProviderToolCall;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Support\ConversationPartition;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Pagination\CursorPaginator;
use Hypervel\Database\Query\Builder;
use Hypervel\Pagination\Cursor;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Throwable;

class DatabaseConversationStore implements ClaimsPendingApprovals, ConversationStore, PaginatesConversations, ResolvesPendingApprovals, StoresConversationTurns, VerifiesConversationOwnership
{
    protected const string TURN_CONTEXT_KEY = '__ai.conversation_turn';

    /**
     * Create a new conversation store instance.
     */
    public function __construct(protected ?string $connection = null)
    {
    }

    /**
     * Get the participant's most recent conversation ID with the given agent.
     *
     * @param class-string<Agent> $agent
     */
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return $this->table($this->messagesTable())
            ->where('participant_type', $participantType)
            ->where('participant_id', $participantId)
            ->where('agent', $agent)
            ->orderByDesc('id')
            ->value('conversation_id');
    }

    /**
     * Determine whether the given conversation was stored for the given participant.
     */
    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool
    {
        $conversation = $this->table($this->conversationsTable())
            ->where('id', $conversationId)
            ->first(['participant_type', 'participant_id']);

        return $conversation !== null
            && $conversation->participant_type === $participantType
            && (string) $conversation->participant_id === (string) $participantId;
    }

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        $this->ensureWriteAllowed();

        $conversationId = $id ?? (string) Str::uuid7();
        $now = now();

        $this->table($this->conversationsTable())->insert($this->partitionedAttributes([
            'id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'title' => $title,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return $conversationId;
    }

    /**
     * Store a new user message for the given conversation and return its ID.
     *
     * @param class-string<Agent> $agent
     */
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        $this->ensureWriteAllowed();

        $messageId = (string) Str::uuid7();

        $now = now();

        // Lock the parent first when the caller has an open transaction.
        $this->touchConversation($conversationId, $now);

        $this->table($this->messagesTable())->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
            'agent' => $agent,
            'role' => 'user',
            'content' => $message->content,
            'attachments' => $message->attachments->toJson(),
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed,
        ]));

        return $messageId;
    }

    /**
     * Store the assistant turn for the given conversation, folding a resumed run into the row it paused on.
     */
    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
    {
        $this->ensureWriteAllowed();

        $persist = function () use ($conversationId, $participantType, $participantId, $prompt, $response, $exception): ?string {
            if ($prompt->runContext()?->hasUnclaimedApprovals()) {
                throw new ApprovalMismatchException('The pending conversation turn was not claimed by this run.', collect());
            }

            $paused = $prompt->hasApprovalDecisions() ? $this->pausedRowFor($conversationId, $prompt) : null;

            if ($paused !== null) {
                $this->touchConversation($conversationId, now());

                $paused = $this->table($this->messagesTable())
                    ->where('id', $paused->id)
                    ->where('conversation_id', $conversationId)
                    ->where('status', MessageStatus::Paused)
                    ->where('approval_claim', $prompt->approvalClaim()?->token)
                    ->lockForUpdate()
                    ->first()
                    ?? throw new ApprovalMismatchException('The paused conversation turn is no longer available.', collect());

                return $this->resumePausedRow($conversationId, $paused, $prompt, $response, $exception);
            }

            $messageId = (string) Str::uuid7();

            $now = now();

            $steps = $this->stepsFor($prompt, $response);

            if ($prompt->hasApprovalDecisions() && blank($response->text) && $steps->every(fn (array $step) => $step['tool_calls'] === [])) {
                return null;
            }

            $this->touchConversation($conversationId, $now);

            $this->table($this->messagesTable())->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
                'agent' => $prompt->agent::class,
                'role' => 'assistant',
                'content' => $response->text,
                'attachments' => '[]',
                'steps' => $steps->toJson(),
                'usage' => json_encode($response->usage),
                'meta' => json_encode($this->metaFor($response, $exception)),
                'status' => $this->statusFor($response, $exception),
                'has_replay_blocks' => $steps->contains(fn (array $step): bool => $step['replay_blocks'] !== []),
            ]));

            if (! $response->hasPendingApprovals()) {
                $this->forgetReplayBlocks($conversationId);
            }

            return $messageId;
        };

        return $this->currentTurn($conversationId) !== null
            ? $persist()
            : DB::connection($this->connection)->transaction($persist);
    }

    /**
     * Commit the conversation and both sides of a turn with one activity update.
     */
    public function storeTurn(string $conversationId, ?string $newConversationTitle, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): StoredTurn
    {
        $this->ensureWriteAllowed();

        return DB::connection($this->connection)->transaction(function () use ($conversationId, $newConversationTitle, $participantType, $participantId, $prompt, $response, $exception): StoredTurn {
            $previous = CoroutineContext::get(self::TURN_CONTEXT_KEY);
            $turn = new ConversationTurnState($this, $conversationId);
            CoroutineContext::set(self::TURN_CONTEXT_KEY, $turn);

            try {
                if ($newConversationTitle !== null) {
                    $conversationId = $this->storeConversation($participantType, $participantId, $newConversationTitle, $conversationId);
                    $turn->conversationId = $conversationId;
                    $turn->touched = true;
                } elseif (! $prompt->hasApprovalDecisions()) {
                    // Lock the parent before an override can insert a message and acquire its foreign-key lock.
                    $this->touchConversation($conversationId, now());
                }

                $userMessageId = $prompt->hasApprovalDecisions() ? null : $this->storeUserMessage(
                    $conversationId,
                    $participantType,
                    $participantId,
                    $prompt->agent::class,
                    new UserMessage($prompt->prompt, $prompt->attachments),
                );

                $assistantMessageId = $this->storeAssistantMessage(
                    $conversationId,
                    $participantType,
                    $participantId,
                    $prompt,
                    $response,
                    $exception,
                );

                return new StoredTurn($conversationId, $userMessageId, $assistantMessageId);
            } finally {
                if ($previous === null) {
                    CoroutineContext::forget(self::TURN_CONTEXT_KEY);
                } else {
                    CoroutineContext::set(self::TURN_CONTEXT_KEY, $previous);
                }
            }
        });
    }

    /**
     * Get the turn whose transaction owns these writes.
     */
    protected function currentTurn(string $conversationId): ?ConversationTurnState
    {
        /** @var null|ConversationTurnState $turn */
        $turn = CoroutineContext::get(self::TURN_CONTEXT_KEY);

        return $turn !== null && $turn->store === $this && $turn->conversationId === $conversationId ? $turn : null;
    }

    /**
     * Determine the status the turn is stored under, given how it ended.
     */
    protected function statusFor(AgentResponse $response, ?Throwable $exception): MessageStatus
    {
        return match (true) {
            $exception !== null => MessageStatus::Failed,
            $response->hasPendingApprovals() => MessageStatus::Paused,
            default => MessageStatus::Completed,
        };
    }

    /**
     * Build the meta the turn is stored under, carrying the error it died with.
     *
     * @return array<string, mixed>
     */
    protected function metaFor(AgentResponse $response, ?Throwable $exception): array
    {
        return $exception === null
            ? $response->meta->toArray()
            : [...$response->meta->toArray(), 'error' => $exception->getMessage()];
    }

    /**
     * Find the row the given resume paused on, matching the turn its decisions name.
     */
    protected function pausedRowFor(string $conversationId, AgentPrompt $prompt): ?object
    {
        if (($claim = $prompt->approvalClaim()) !== null) {
            // Settlement checks this claim under its row lock.
            return (object) ['id' => $claim->messageId];
        }

        $decided = array_keys($prompt->approvalDecisions->all());

        $named = $this->assistantRows($conversationId)
            ->where('status', MessageStatus::Paused)
            ->whereNull('approval_claim')
            ->select(['id', 'steps'])
            ->lazyByIdDesc(100)
            ->first(fn (object $record): bool => array_intersect($this->gatedCallIds($record), $decided) !== []);

        if ($named !== null) {
            return $named;
        }

        $newest = $this->assistantRows($conversationId)->first(['id', 'status', 'approval_claim']);

        return $newest === null || $newest->approval_claim !== null || MessageStatus::from($newest->status) !== MessageStatus::Paused ? null : $newest;
    }

    /**
     * Append the steps a resumed run made to the row its turn paused on.
     */
    protected function resumePausedRow(string $conversationId, object $paused, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): string
    {
        $steps = $this->decodedSteps($paused);

        if (($this->decoded($paused->meta)['provider'] ?? null) !== $response->meta->provider) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        if ($response->steps->isNotEmpty()) {
            $steps = $steps->concat($this->stepsFor($prompt, $response));
        }

        if (! $response->hasPendingApprovals()) {
            $steps = $this->withoutReplayBlocks($steps);
        }

        $now = now();

        $this->table($this->messagesTable())->where('id', $paused->id)->update([
            'content' => blank($response->text) ? $paused->content : $response->text,
            'steps' => $steps->toJson(),
            'usage' => json_encode(TextUsage::fromArray($this->decoded($paused->usage))->add($response->usage)),
            'meta' => json_encode($this->mergedMeta($paused, $response, $exception)),
            'status' => $this->statusFor($response, $exception),
            'approval_claim' => null,
            'has_replay_blocks' => $steps->contains(fn (array $step): bool => $step['replay_blocks'] !== []),
            'updated_at' => $now,
        ]);

        if (! $response->hasPendingApprovals()) {
            $this->forgetReplayBlocks($conversationId);
        }

        return $paused->id;
    }

    /**
     * Keep the citations the paused half of the turn collected, under the resuming provider and model.
     *
     * @return array<string, mixed>
     */
    protected function mergedMeta(object $paused, AgentResponse $response, ?Throwable $exception = null): array
    {
        return [
            ...$this->metaFor($response, $exception),
            'citations' => [...$this->decoded($paused->meta)['citations'] ?? [], ...$response->meta->citations->all()],
        ];
    }

    /**
     * Serialize the turn's steps, one entry per model round-trip.
     *
     * @return Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function stepsFor(AgentPrompt $prompt, AgentResponse $response): Collection
    {
        $reasons = $this->pendingReasonsFor($response);

        if ($response->steps->isNotEmpty()) {
            return $response->steps->values()->map(fn (Step $step): array => [
                'content' => $step->text,
                'tool_calls' => $this->toolCallsFor($step->toolCalls, $step->toolResults, $reasons),
                'reasoning' => $step->reasoning,
                'replay_blocks' => $response->hasPendingApprovals() ? $step->replayBlocks : [],
                'provider_tool_calls' => array_map(fn (ProviderToolCall $call): array => $call->toArray(), $step->providerToolCalls),
            ]);
        }

        // A resume that ran no step only carries approval results already recorded on the paused row.
        return collect([[
            'content' => $response->text,
            'tool_calls' => $this->toolCallsFor(
                $response->toolCalls->all(),
                $prompt->hasApprovalDecisions() ? [] : $response->toolResults->all(),
                $reasons,
            ),
            'reasoning' => $response->reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]]);
    }

    /**
     * Pair a step's tool calls with the results they were answered by, marking those awaiting approval with their reason.
     *
     * @param iterable<int, ToolCall> $toolCalls
     * @param iterable<int, ToolResult> $toolResults
     * @param Collection<string, null|string> $reasons
     * @return list<array<string, mixed>>
     */
    protected function toolCallsFor(iterable $toolCalls, iterable $toolResults, Collection $reasons): array
    {
        $results = collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);

        return collect($toolCalls)->map(function (ToolCall $toolCall) use ($results, $reasons): array {
            $result = $results->get($toolCall->id);

            $stored = Arr::except($toolCall->toArray(), ['reasoning_id', 'reasoning_summary', 'reasoning_encrypted_content']);

            if ($toolCall->thoughtSignature === null) {
                unset($stored['thought_signature']);
            }

            return [
                ...$stored,
                ...$reasons->has($toolCall->id) ? ['approval_reason' => $reasons[$toolCall->id]] : [],
                ...$result === null ? [] : Arr::only($result->toArray(), ['result', 'denied', 'failed']),
                ...$result?->resultId === null ? [] : ['result_id' => $result->resultId],
            ];
        })->values()->all();
    }

    /**
     * Drop the raw provider blocks of the paused rows a now-completed turn resumed from.
     */
    protected function forgetReplayBlocks(string $conversationId): void
    {
        $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('status', MessageStatus::Paused)
            ->where('has_replay_blocks', true)
            ->whereNull('approval_claim')
            ->select('id')
            ->lazyById(100)
            ->each(function (object $candidate): void {
                $record = $this->table($this->messagesTable())
                    ->where('id', $candidate->id)
                    ->where('status', MessageStatus::Paused)
                    ->where('has_replay_blocks', true)
                    ->whereNull('approval_claim')
                    ->lockForUpdate()
                    ->first(['id', 'steps']);

                if ($record === null) {
                    return;
                }

                $this->table($this->messagesTable())->where('id', $record->id)->update([
                    'steps' => $this->withoutReplayBlocks($this->decodedSteps($record))->toJson(),
                    'has_replay_blocks' => false,
                ]);
            });
    }

    /**
     * Drop the raw provider blocks from the given steps.
     *
     * @param Collection<int, array<string, mixed>> $steps
     * @return Collection<int, array<string, mixed>>
     */
    protected function withoutReplayBlocks(Collection $steps): Collection
    {
        return $steps->map(fn (array $step): array => [...$step, 'replay_blocks' => []]);
    }

    /**
     * Get the reasons a response paused on, keyed by tool call ID.
     *
     * @return Collection<string, null|string>
     */
    protected function pendingReasonsFor(AgentResponse $response): Collection
    {
        return $response->pendingApprovals->mapWithKeys(fn (PendingApproval $approval) => [$approval->id => $approval->reason]);
    }

    /**
     * Decode a stored JSON column.
     *
     * @return array<array-key, mixed>
     */
    protected function decoded(?string $json): array
    {
        return is_array($decoded = json_decode($json ?? '', true)) ? $decoded : [];
    }

    /**
     * Get the tool-call IDs a stored row is still awaiting a decision on.
     *
     * @return array<int, string>
     */
    protected function pausedCallIds(object $record): array
    {
        return $this->pendingApprovalsIn($record)->map(fn (PendingApproval $approval) => $approval->id)->all();
    }

    /**
     * Get the IDs of a stored row's tool calls that were gated behind an approval.
     *
     * @return array<int, string>
     */
    protected function gatedCallIds(object $record): array
    {
        return $this->decodedSteps($record)->flatMap(fn (array $step) => $step['tool_calls'])
            ->filter(fn (array $toolCall): bool => array_key_exists('approval_reason', $toolCall))
            ->pluck('id')
            ->all();
    }

    /**
     * Rebuild the approvals a stored row is still awaiting a decision on.
     *
     * @return Collection<int, PendingApproval>
     */
    protected function pendingApprovalsIn(object $record): Collection
    {
        return $this->decodedSteps($record)->flatMap(fn (array $step) => $step['tool_calls'])
            ->filter(PendingApproval::isPending(...))
            ->map(fn (array $toolCall) => new PendingApproval(
                $toolCall['id'],
                $toolCall['name'],
                $toolCall['arguments'],
                $toolCall['approval_reason'],
            ))->values();
    }

    /**
     * Update the conversation's activity timestamp.
     */
    protected function touchConversation(string $conversationId, mixed $timestamp): void
    {
        $turn = $this->currentTurn($conversationId);

        if ($turn?->touched) {
            return;
        }

        $this->table($this->conversationsTable())
            ->where('id', $conversationId)
            ->update(['updated_at' => $timestamp]);

        if ($turn !== null) {
            $turn->touched = true;
        }
    }

    /**
     * Build the message row attributes.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function messageAttributes(string $messageId, string $conversationId, ?string $participantType, string|int|null $participantId, mixed $now, array $attributes): array
    {
        return $this->partitionedAttributes(array_merge($attributes, [
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /**
     * Get the latest messages for the given conversation.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $records = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        return $records->flatMap(fn (object $record): array => $record->role === 'user'
            ? [$this->userMessageFrom($record)]
            : $this->assistantTurnFrom($record));
    }

    /**
     * Rebuild a stored user turn.
     */
    protected function userMessageFrom(object $record): Message
    {
        $attachments = $this->rehydrateAttachments($record->attachments);

        return $attachments->isNotEmpty()
            ? new UserMessage($record->content, $attachments)
            : new Message('user', $record->content);
    }

    /**
     * Rebuild a stored assistant turn step by step, so every tool result answers the message that made its call.
     *
     * @return array<int, Message>
     */
    protected function assistantTurnFrom(object $record): array
    {
        $pending = $this->pausedCallIds($record);
        $provider = $this->decoded($record->meta)['provider'] ?? null;
        $failed = MessageStatus::from($record->status) === MessageStatus::Failed;
        $claimed = $record->approval_claim !== null;

        return $this->decodedSteps($record)->flatMap(function (array $step) use ($pending, $provider, $failed, $claimed): array {
            $content = $step['content'];

            $replayed = collect($step['tool_calls'])
                ->filter(fn (array $toolCall): bool => $failed || $claimed || PendingApproval::isAnswered($toolCall) || in_array($toolCall['id'] ?? null, $pending, true))
                ->values();

            $toolCalls = $replayed->map(ToolCall::fromArray(...));

            // A live or crashed claim must not make its unanswered calls executable by another run.
            $toolResults = $replayed
                ->filter(fn (array $toolCall): bool => PendingApproval::isAnswered($toolCall) || $failed || $claimed)
                ->map(fn (array $toolCall): ToolResult => PendingApproval::isAnswered($toolCall)
                    ? ToolResult::fromArray($toolCall)
                    : ($claimed
                        ? new ToolResult($toolCall['id'], $toolCall['name'], $toolCall['arguments'] ?? [], 'This tool call is in progress or its outcome is unknown. Do not run it again.', $toolCall['result_id'] ?? null)
                        : $this->interruptedResultFor($toolCall)))
                ->values();

            // Raw blocks still name a dropped call, so a step missing one rebuilds generically rather than replaying a call no result answers...
            $replayBlocks = $replayed->count() === count($step['tool_calls']) ? $step['replay_blocks'] : [];

            $isBlank = $content === '' && $toolCalls->isEmpty() && $replayBlocks === [];

            $messages = $isBlank ? [] : [new AssistantMessage($content, $toolCalls, $replayBlocks, $provider)];

            if ($toolResults->isNotEmpty()) {
                $messages[] = new ToolResultMessage($toolResults);
            }

            return $messages;
        })->all();
    }

    /**
     * Build the result a failed turn's unanswered call replays with.
     *
     * @param array<string, mixed> $toolCall
     */
    protected function interruptedResultFor(array $toolCall): ToolResult
    {
        return new ToolResult(
            $toolCall['id'],
            $toolCall['name'],
            $toolCall['arguments'] ?? [],
            'This tool call was interrupted before a result was recorded, so it may or may not have run.',
            $toolCall['result_id'] ?? null,
        );
    }

    /**
     * Decode a stored row's steps.
     *
     * @return Collection<int, array{content: string, tool_calls: array, reasoning: string, replay_blocks: array, provider_tool_calls: array}>
     */
    protected function decodedSteps(object $record): Collection
    {
        return collect($this->decoded($record->steps))->map(fn (array $step): array => [
            'content' => (string) ($step['content'] ?? ''),
            'tool_calls' => array_values($step['tool_calls'] ?? []),
            'reasoning' => (string) ($step['reasoning'] ?? ''),
            'replay_blocks' => $step['replay_blocks'] ?? [],
            'provider_tool_calls' => array_values($step['provider_tool_calls'] ?? []),
        ])->values();
    }

    /**
     * Paginate the given conversation's messages, newest first.
     *
     * @return CursorPaginator<int, StoredMessage>
     */
    public function paginateConversationMessages(string $conversationId, int $perPage = 15, string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], $cursorName, $cursor)
            ->through(fn (object $record): StoredMessage => StoredMessage::fromArray((array) $record));
    }

    /**
     * Get the tool calls the given conversation's newest turn is still waiting on.
     *
     * @return list<PendingApproval>
     */
    public function pendingApprovalsFor(string $conversationId): array
    {
        $newest = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->first(['role', 'steps', 'status', 'approval_claim']);

        return $newest === null || $newest->approval_claim !== null || $newest->role !== 'assistant' || MessageStatus::from($newest->status) !== MessageStatus::Paused
            ? []
            : $this->pendingApprovalsIn($newest)->all();
    }

    /**
     * Rehydrate attachments from their stored JSON representation.
     *
     * @return Collection<int, File>
     */
    protected function rehydrateAttachments(string $attachments): Collection
    {
        $decoded = json_decode($attachments, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new InvalidArgumentException('Stored conversation attachments must be a JSON array.');
        }

        if ($decoded === []) {
            return collect();
        }

        return collect($decoded)
            ->map(function (mixed $attachment): ?File {
                if (! is_array($attachment)) {
                    throw new InvalidArgumentException('Stored conversation attachment entries must be objects.');
                }

                return File::fromArray($attachment);
            })
            ->filter()
            ->values();
    }

    /**
     * Claim the paused turn matching the complete pending call set before any tools execute.
     *
     * @param list<string> $toolCallIds
     */
    public function claimPendingApprovals(string $conversationId, array $toolCallIds): ?ApprovalClaim
    {
        $this->ensureWriteAllowed();

        if ($toolCallIds === []) {
            return null;
        }

        sort($toolCallIds, SORT_STRING);

        $candidate = $this->assistantRows($conversationId)
            ->where('status', MessageStatus::Paused)
            ->select(['id', 'steps'])
            ->lazyByIdDesc(100)
            ->first(function (object $record) use ($toolCallIds): bool {
                $pending = $this->pausedCallIds($record);
                sort($pending, SORT_STRING);

                return $pending === $toolCallIds;
            });

        if ($candidate === null) {
            return null;
        }

        return DB::connection($this->connection)->transaction(function () use ($candidate, $toolCallIds): ?ApprovalClaim {
            $record = $this->table($this->messagesTable())
                ->where('id', $candidate->id)
                ->lockForUpdate()
                ->first(['id', 'role', 'status', 'approval_claim', 'steps']);

            if ($record === null || $record->role !== 'assistant' || $record->status !== MessageStatus::Paused->value || $record->approval_claim !== null) {
                return null;
            }

            $pending = $this->pausedCallIds($record);
            sort($pending, SORT_STRING);

            if ($pending !== $toolCallIds) {
                return null;
            }

            $claim = new ApprovalClaim($record->id, (string) Str::uuid7());

            $claimed = $this->table($this->messagesTable())
                ->where('id', $record->id)
                ->where('status', MessageStatus::Paused)
                ->whereNull('approval_claim')
                ->update([
                    'approval_claim' => $claim->token,
                    'updated_at' => now(),
                ]);

            return $claimed === 1 ? $claim : null;
        });
    }

    /**
     * Record one tool outcome while its claim still owns the paused turn.
     */
    public function recordApprovalResult(ApprovalClaim $claim, ToolResult $result): void
    {
        $this->ensureWriteAllowed();

        DB::connection($this->connection)->transaction(function () use ($claim, $result): void {
            $record = $this->table($this->messagesTable())
                ->where('id', $claim->messageId)
                ->where('approval_claim', $claim->token)
                ->where('status', MessageStatus::Paused)
                ->lockForUpdate()
                ->first(['id', 'steps']);

            if ($record === null || ! in_array($result->id, $this->gatedCallIds($record), true)) {
                throw new ApprovalMismatchException('The tool result does not belong to the claimed conversation turn.', collect());
            }

            $this->table($this->messagesTable())->where('id', $record->id)->update([
                'steps' => $this->stepsWithApprovalResults($record, [$result])->toJson(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Durably record resolved approval results on the paused turn before the run continues.
     *
     * @param array<int, ToolResult> $toolResults
     *
     * @throws ApprovalMismatchException when no paused row matches the resolved results
     */
    public function storeApprovalResults(string $conversationId, array $toolResults): void
    {
        if ($toolResults === []) {
            return;
        }

        $this->ensureWriteAllowed();

        $resultIds = array_map(fn (ToolResult $result) => $result->id, $toolResults);
        $newest = null;
        $candidate = $this->assistantRows($conversationId)
            ->where('status', MessageStatus::Paused)
            ->whereNull('approval_claim')
            ->select(['id', 'steps'])
            ->lazyByIdDesc(100)
            ->first(function (object $record) use (&$newest, $resultIds): bool {
                $newest ??= $record;

                return array_intersect($this->pausedCallIds($record), $resultIds) !== [];
            });

        DB::connection($this->connection)->transaction(function () use ($candidate, $newest, $toolResults, $resultIds): void {
            $row = $candidate === null ? null : $this->table($this->messagesTable())
                ->where('id', $candidate->id)
                ->where('status', MessageStatus::Paused)
                ->whereNull('approval_claim')
                ->lockForUpdate()
                ->first(['id', 'steps']);

            if ($row === null || array_intersect($this->pausedCallIds($row), $resultIds) === []) {
                throw new ApprovalMismatchException(
                    'The approval results do not match a paused conversation turn.',
                    $newest === null ? collect() : $this->pendingApprovalsIn($newest),
                );
            }

            $this->table($this->messagesTable())
                ->where('id', $row->id)
                ->update(['steps' => $this->stepsWithApprovalResults($row, $toolResults)->toJson(), 'updated_at' => now()]);
        });
    }

    /**
     * Merge results into their original calls without overwriting recorded outcomes.
     *
     * @param array<int, ToolResult> $toolResults
     * @return Collection<int, array<string, mixed>>
     */
    protected function stepsWithApprovalResults(object $row, array $toolResults): Collection
    {
        $resolved = collect($toolResults)->keyBy(fn (ToolResult $result): string => $result->id);

        return $this->decodedSteps($row)->map(function (array $step) use ($resolved): array {
            $step['tool_calls'] = array_map(function (array $toolCall) use ($resolved): array {
                $result = $resolved->get($toolCall['id'] ?? '');

                return $result === null || PendingApproval::isAnswered($toolCall)
                    ? $toolCall
                    // Arguments come along because an edited approval runs the tool with different ones than the call asked for...
                    : [
                        ...$toolCall,
                        ...Arr::only($result->toArray(), ['arguments', 'result', 'denied', 'failed']),
                        ...$result->resultId === null ? [] : ['result_id' => $result->resultId],
                    ];
            }, $step['tool_calls']);

            return $step;
        });
    }

    /**
     * Query the conversation's assistant rows, newest first.
     */
    protected function assistantRows(string $conversationId): Builder
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderByDesc('id');
    }

    /**
     * Get a query builder for the given table using the configured connection.
     */
    protected function table(string $table): Builder
    {
        $query = DB::connection($this->connection)->table($table);
        $partition = ConversationPartition::current();

        return $partition === null ? $query : $query->where($partition->column, $partition->value);
    }

    /**
     * Stamp raw store inserts with the current partition.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function partitionedAttributes(array $attributes): array
    {
        $partition = ConversationPartition::current();

        return $partition === null ? $attributes : [...$attributes, $partition->column => $partition->value];
    }

    /**
     * Allow specialized stores to reject writes without restricting reads.
     */
    protected function ensureWriteAllowed(): void
    {
    }

    /**
     * Resolve the conversations table name from config.
     */
    protected function conversationsTable(): string
    {
        return config()->string('ai.conversations.tables.conversations', Conversation::DEFAULT_TABLE);
    }

    /**
     * Resolve the messages table name from config.
     */
    protected function messagesTable(): string
    {
        return config()->string('ai.conversations.tables.messages', ConversationMessage::DEFAULT_TABLE);
    }
}
