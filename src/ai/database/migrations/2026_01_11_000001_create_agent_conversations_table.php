<?php

declare(strict_types=1);

use Hypervel\Ai\Migrations\AiMigration;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Models\ConversationMessage;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Support\Facades\Schema;

return new class extends AiMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $conversationsTable = config()->string('ai.conversations.tables.conversations', Conversation::DEFAULT_TABLE);
        $messagesTable = config()->string('ai.conversations.tables.messages', ConversationMessage::DEFAULT_TABLE);

        Schema::create($conversationsTable, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('participant_type')->nullable();
            match (Builder::$defaultMorphKeyType) {
                'uuid' => $table->uuid('participant_id')->nullable(),
                'ulid' => $table->ulid('participant_id')->nullable(),
                default => $table->unsignedBigInteger('participant_id')->nullable(),
            };
            $table->string('title');
            $table->timestamps();

            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        Schema::create($messagesTable, function (Blueprint $table) use ($conversationsTable): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained($conversationsTable)->cascadeOnDelete();
            $table->string('participant_type')->nullable();
            match (Builder::$defaultMorphKeyType) {
                'uuid' => $table->uuid('participant_id')->nullable(),
                'ulid' => $table->ulid('participant_id')->nullable(),
                default => $table->unsignedBigInteger('participant_id')->nullable(),
            };
            $table->string('agent');
            $table->string('role');
            $table->longText('content');
            $table->jsonb('attachments');
            $table->jsonb('steps');
            $table->jsonb('usage');
            $table->jsonb('meta');
            $table->string('status');
            $table->uuid('approval_claim')->nullable();
            $table->timestamp('approval_claimed_at')->nullable();
            $table->boolean('has_replay_blocks')->default(false);
            $table->timestamps();

            $table->index(['conversation_id', 'id'], 'conversation_messages_index');
            $table->index(['participant_type', 'participant_id', 'agent', 'id'], 'participant_index');
            $table->index(['conversation_id', 'role', 'status', 'id'], 'conversation_status_index');
            $table->index(['conversation_id', 'has_replay_blocks', 'id'], 'conversation_replay_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config()->string('ai.conversations.tables.messages', ConversationMessage::DEFAULT_TABLE));
        Schema::dropIfExists(config()->string('ai.conversations.tables.conversations', Conversation::DEFAULT_TABLE));
    }
};
