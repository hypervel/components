<?php

declare(strict_types=1);

namespace Hypervel\Ai\Models;

use Hypervel\Ai\Concerns\HasConversationPartition;
use Hypervel\Database\Eloquent\Attributes\WithoutIncrementing;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use Override;

#[WithoutIncrementing]
class Conversation extends Model
{
    use HasConversationPartition;

    public const string DEFAULT_TABLE = 'agent_conversations';

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
     * Get the messages for the conversation.
     *
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        $this->ensureConversationRelationshipPartition();

        return $this->hasMany(ConversationMessage::class, 'conversation_id');
    }

    /**
     * Get the participant that owns the conversation.
     *
     * @return MorphTo<Model, $this>
     */
    public function participant(): MorphTo
    {
        $this->ensureConversationRelationshipPartition();

        return $this->morphTo();
    }

    /**
     * Get the table associated with the model.
     */
    #[Override]
    public function getTable(): string
    {
        return config()->string('ai.conversations.tables.conversations', self::DEFAULT_TABLE);
    }

    /**
     * Get the database connection for the model.
     */
    #[Override]
    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }

    /**
     * Resolve the participant_type discriminator to record for the participant.
     */
    public static function participantType(object $participant): string
    {
        return $participant instanceof Model
            ? $participant->getMorphClass()
            : $participant::class;
    }

    /**
     * Resolve the participant_id key to record for the participant.
     */
    public static function participantKey(object $participant): string|int
    {
        if ($participant instanceof Model) {
            return $participant->getKey();
        }

        return $participant->id ?? throw new InvalidArgumentException(
            'The conversation participant must be an Eloquent model or expose an [id] property.'
        );
    }
}
