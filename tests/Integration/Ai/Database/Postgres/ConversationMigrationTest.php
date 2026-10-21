<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database\Postgres;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Ai\TestCase;

#[RequiresDatabase('pgsql')]
class ConversationMigrationTest extends TestCase
{
    /**
     * Select schema-qualified tables on the configured integration connection.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'database.default' => getenv('DB_CONNECTION') ?: 'testing',
            'ai.conversations.tables.conversations' => 'public.ai_schema_conversations',
            'ai.conversations.tables.messages' => 'public.ai_schema_messages',
        ]);
    }

    /**
     * Run the migration explicitly so it can be rolled back even after a failure.
     */
    protected function defineDatabaseMigrations(): void
    {
    }

    public function testMigrationSupportsSchemaQualifiedTables(): void
    {
        $migration = require dirname(__DIR__, 5) . '/src/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php';

        try {
            $migration->up();

            $this->assertTrue(Schema::hasTable('public.ai_schema_conversations'));
            $this->assertTrue(Schema::hasTable('public.ai_schema_messages'));
            $this->assertContains('ai_schema_conversations_participant_index', array_column(Schema::getIndexes('public.ai_schema_conversations'), 'name'));
            $this->assertContains('ai_schema_messages_participant_index', array_column(Schema::getIndexes('public.ai_schema_messages'), 'name'));
        } finally {
            $migration->down();
        }

        $this->assertFalse(Schema::hasTable('public.ai_schema_messages'));
        $this->assertFalse(Schema::hasTable('public.ai_schema_conversations'));
    }
}
