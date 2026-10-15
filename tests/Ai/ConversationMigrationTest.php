<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class ConversationMigrationTest extends TestCase
{
    /**
     * Run the migration explicitly after selecting the participant key type.
     */
    protected function defineDatabaseMigrations(): void
    {
    }

    /**
     * Configure conversation storage independently of the application's default database.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'database.connections.conversations' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => 'app_',
                'prefix_indexes' => true,
                'foreign_key_constraints' => true,
            ],
            'ai.conversations.connection' => 'conversations',
            'ai.conversations.tables' => [
                'conversations' => 'custom_conversations',
                'messages' => 'custom_messages',
            ],
        ]);
    }

    #[DataProvider('participantKeys')]
    public function testMigrationUsesConfiguredTablesConnectionAndParticipantKeys(string $morphType, int|string $participantId, string $columnType): void
    {
        Builder::defaultMorphKeyType($morphType);
        $options = [
            '--path' => __DIR__ . '/../../src/ai/database/migrations',
            '--realpath' => true,
        ];
        $this->artisan('migrate', $options)->assertSuccessful();

        $schema = Schema::connection('conversations');
        $this->assertFalse(Schema::hasTable('custom_conversations'));
        $this->assertSame($columnType, $schema->getColumnType('custom_conversations', 'participant_id'));
        $this->assertSame($columnType, $schema->getColumnType('custom_messages', 'participant_id'));
        $this->assertSame('app_custom_conversations', $schema->getForeignKeys('custom_messages')[0]['foreign_table']);
        $this->assertContains('app_custom_conversations_participant_index', array_column($schema->getIndexes('custom_conversations'), 'name'));
        $this->assertEqualsCanonicalizing([
            'app_custom_messages_conversation_index',
            'app_custom_messages_participant_index',
            'app_custom_messages_status_index',
            'app_custom_messages_replay_index',
        ], array_column(array_filter($schema->getIndexes('custom_messages'), fn (array $index): bool => ! $index['primary']), 'name'));

        $conversation = Conversation::create([
            'id' => (string) Str::uuid7(),
            'title' => 'Conversation',
            'participant_type' => 'user',
            'participant_id' => $participantId,
        ]);
        $message = $conversation->messages()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => 'user',
            'participant_id' => $participantId,
            'agent' => 'Agent',
            'role' => 'user',
            'content' => 'Hello',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $this->assertSame((string) $participantId, (string) $message->fresh()->participant_id);

        $this->artisan('migrate:rollback', $options)->assertSuccessful();
        $this->assertFalse($schema->hasTable('custom_messages'));
        $this->assertFalse($schema->hasTable('custom_conversations'));
    }

    /**
     * Cover the supported participant identifier types.
     */
    public static function participantKeys(): array
    {
        return [
            'integer' => ['int', 42, 'integer'],
            'uuid' => ['uuid', '00000000-0000-4000-8000-000000000001', 'varchar'],
            'ulid' => ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'varchar'],
        ];
    }

    public function testMigrationDoesNotCollideWithAnotherTablesIndexes(): void
    {
        $schema = Schema::connection('conversations');
        $schema->create('participants', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->index('participant_index');
        });

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../../src/ai/database/migrations',
            '--realpath' => true,
        ])->assertSuccessful();

        $this->assertTrue($schema->hasTable('custom_messages'));
    }
}
