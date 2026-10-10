<?php

declare(strict_types=1);

namespace Hypervel\Ai\Models;

use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Concerns\HasConversationPartition;
use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Database\Eloquent\Attributes\WithoutIncrementing;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Support\Arr;
use Override;

/**
 * @property string $id
 * @property string $role
 * @property ?string $content
 * @property ?array $attachments
 * @property ?array $steps
 * @property-read array $tool_calls
 * @property-read array $provider_tool_calls
 * @property-read array $tool_results
 * @property MessageStatus $status
 */
#[WithoutIncrementing]
class ConversationMessage extends Model
{
    use HasConversationPartition;

    public const string DEFAULT_TABLE = 'agent_conversation_messages';

    /**
     * The data type of the primary key ID.
     */
    protected string $keyType = 'string';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected array $guarded = [];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected array $appends = ['tool_calls', 'tool_results', 'provider_tool_calls'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'attachments' => 'array',
        'steps' => 'array',
        'usage' => 'array',
        'meta' => 'array',
        'status' => MessageStatus::class,
        'has_replay_blocks' => 'boolean',
    ];

    /**
     * The tool calls made across every step of the turn, in step order.
     */
    protected function toolCalls(): Attribute
    {
        return Attribute::get(fn (): array => Arr::collapse(array_column($this->steps ?? [], 'tool_calls')));
    }

    /**
     * The provider-hosted tool calls made across every step of the turn, in step order.
     */
    protected function providerToolCalls(): Attribute
    {
        return Attribute::get(fn (): array => Arr::collapse(array_column($this->steps ?? [], 'provider_tool_calls')));
    }

    /**
     * The tool results recorded across every step of the turn, in step order.
     */
    protected function toolResults(): Attribute
    {
        return Attribute::get(fn (): array => array_values(array_map(
            fn (array $toolCall): array => Arr::only($toolCall, ['id', 'name', 'arguments', 'result', 'result_id', 'denied', 'failed']),
            array_filter($this->tool_calls, PendingApproval::isAnswered(...)),
        )));
    }

    /**
     * Get the conversation that owns the message.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        $this->ensureConversationRelationshipPartition();

        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * Get the table associated with the model.
     */
    #[Override]
    public function getTable(): string
    {
        return config()->string('ai.conversations.tables.messages', self::DEFAULT_TABLE);
    }

    /**
     * Get the database connection for the model.
     */
    #[Override]
    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }
}
