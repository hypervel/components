<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database\MySql;

use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Ai\TestCase;

#[RequiresDatabase('mysql')]
class ConversationMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Use the configured integration database.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.default', getenv('DB_CONNECTION') ?: 'testing');
    }

    public function testMessagesCanStoreMoreThanSixtyFourKilobytes(): void
    {
        $content = str_repeat('A paragraph from an attached document. ', 2048);
        $conversation = Conversation::create(['id' => (string) Str::uuid7(), 'title' => 'Document review']);
        $message = $conversation->messages()->create([
            'id' => (string) Str::uuid7(),
            'agent' => 'Agent',
            'role' => 'user',
            'content' => $content,
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $this->assertSame($content, $message->fresh()->content);
    }
}
