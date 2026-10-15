<?php

declare(strict_types=1);

use Hypervel\Ai\Migrations\AiMigration;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Models\ConversationMessage;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;

return new class extends AiMigration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $conversationsTable = config()->string('ai.conversations.tables.conversations', Conversation::DEFAULT_TABLE);
        $messagesTable = config()->string('ai.conversations.tables.messages', ConversationMessage::DEFAULT_TABLE);
        $connection = Schema::getConnection();
        $prefix = $connection->getConfig('prefix_indexes') ? $connection->getTablePrefix() : '';
        $indexName = static function (string $table, string $suffix) use ($prefix): string {
            $stem = str_replace('-', '_', strtolower($prefix . Str::afterLast($table, '.')));
            $name = $stem . $suffix;

            // PostgreSQL limits identifiers to 63 bytes; retain readable names when they fit.
            return strlen($name) <= 63
                ? $name
                : mb_strcut($name, 0, 30, 'UTF-8') . '_' . hash('xxh128', $name);
        };

        Schema::create($conversationsTable, function (Blueprint $table) use ($conversationsTable, $indexName): void {
            $table->uuid('id')->primary();
            $table->string('participant_type')->nullable();
            match (Builder::$defaultMorphKeyType) {
                'uuid' => $table->uuid('participant_id')->nullable(),
                'ulid' => $table->ulid('participant_id')->nullable(),
                default => $table->unsignedBigInteger('participant_id')->nullable(),
            };
            $table->string('title');
            $table->timestamps();

            $table->index(['participant_type', 'participant_id', 'updated_at'], $indexName($conversationsTable, '_participant_index'));
        });

        Schema::create($messagesTable, function (Blueprint $table) use ($conversationsTable, $messagesTable, $indexName): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')
                ->constrained($conversationsTable, indexName: $indexName($messagesTable, '_conversation_id_foreign'))
                ->cascadeOnDelete();
            $table->string('participant_type')->nullable();
            match (Builder::$defaultMorphKeyType) {
                'uuid' => $table->uuid('participant_id')->nullable(),
                'ulid' => $table->ulid('participant_id')->nullable(),
                default => $table->unsignedBigInteger('participant_id')->nullable(),
            };
            $table->string('agent');
            $table->string('role');
            $table->longText('content');
            // Keep opaque JSON unchanged, including key order and escaped NUL characters.
            $table->longText('attachments');
            $table->longText('steps');
            $table->longText('usage');
            $table->longText('meta');
            $table->string('status');
            $table->uuid('approval_claim')->nullable();
            $table->boolean('has_replay_blocks')->default(false);
            $table->timestamps();

            $table->index(['conversation_id', 'id'], $indexName($messagesTable, '_conversation_index'));
            $table->index(['participant_type', 'participant_id', 'agent', 'id'], $indexName($messagesTable, '_participant_index'));
            $table->index(['conversation_id', 'status', 'id'], $indexName($messagesTable, '_status_index'));
            $table->index(['conversation_id', 'has_replay_blocks', 'id'], $indexName($messagesTable, '_replay_index'));
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
