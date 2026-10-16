<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database\MySql;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Ai\TestCase;

#[RequiresDatabase('mysql')]
class ConversationMigrationTest extends TestCase
{
    /**
     * Select long table names on the configured integration connection.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'database.default' => getenv('DB_CONNECTION') ?: 'testing',
            'ai.conversations.tables.conversations' => 'customer_support_archived_agent_conversations_2026',
            'ai.conversations.tables.messages' => 'customer_support_archived_agent_conversation_messages_2026',
        ]);
    }

    /**
     * Run the migration explicitly so it can be rolled back even after a failure.
     */
    protected function defineDatabaseMigrations(): void
    {
    }

    public function testMigrationBoundsIndexAndForeignKeyNamesForLongTables(): void
    {
        $migration = require dirname(__DIR__, 5) . '/src/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php';
        $conversationsTable = config()->string('ai.conversations.tables.conversations');
        $messagesTable = config()->string('ai.conversations.tables.messages');

        try {
            $migration->up();

            $names = [];

            foreach ([$conversationsTable, $messagesTable] as $table) {
                foreach (Schema::getIndexes($table) as $index) {
                    if (! $index['primary']) {
                        $this->assertLessThanOrEqual(63, strlen($index['name']));
                        $names[] = $index['name'];
                    }
                }
            }

            $this->assertCount(5, array_unique($names));
            $foreignKeys = Schema::getForeignKeys($messagesTable);
            $this->assertCount(1, $foreignKeys);
            $this->assertLessThanOrEqual(63, strlen($foreignKeys[0]['name']));
            $this->assertSame($conversationsTable, $foreignKeys[0]['foreign_table']);
        } finally {
            $migration->down();
        }

        $this->assertFalse(Schema::hasTable($messagesTable));
        $this->assertFalse(Schema::hasTable($conversationsTable));
    }
}
