<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Database;

use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Approvals\ApprovalClaim;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Ai\Storage\StoredTurn;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Ai\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

abstract class ConversationConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * Give concurrent requests separate pooled sessions with committed setup data.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $connection = getenv('DB_CONNECTION') ?: 'testing';
        $config->set('database.default', $connection);
        $config->set("database.connections.{$connection}.pool.testing_enabled", true);
        $config->set("database.connections.{$connection}.pool.max_connections", 4);
        $config->set("database.connections.{$connection}.pool.heartbeat_interval", null);
    }

    /**
     * Migrate only the conversation tables this test needs.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--realpath' => true,
            '--path' => dirname(__DIR__, 4) . '/src/ai/database/migrations',
        ];
    }

    public function testOverlappingTurnsDoNotDeadlockWhenTouchingTheirConversation(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation(null, null, 'Shared conversation');
        DB::table('agent_conversations')->where('id', $conversationId)->update(['updated_at' => '2020-01-01 00:00:00']);
        $arrived = new Channel(2);
        $release = new Channel(2);
        $provider = m::mock(TextProvider::class);
        $sessionIds = [];

        $write = function (string $text) use ($store, $conversationId, $arrived, $release, $provider, &$sessionIds): StoredTurn {
            $connection = DB::connection();
            $connection->beforeExecuting(function (string $query) use ($arrived, $release, $connection, &$sessionIds): void {
                if (str_starts_with($query, 'update ' . $connection->getQueryGrammar()->wrapTable('agent_conversations'))) {
                    $sessionIds[] = $connection->scalar($connection->getDriverName() === 'pgsql' ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()');
                    $this->assertTrue($arrived->push(true, 5));
                    $this->assertTrue($release->pop(5));
                }
            });

            return $store->storeTurn(
                $conversationId,
                null,
                null,
                null,
                new AgentPrompt(new AnonymousAgent('', [], []), $text, [], $provider, 'model'),
                new AgentResponse($text, 'Reply to ' . $text, new TextUsage, new Meta('test', 'model')),
            );
        };

        try {
            $results = parallel([
                'first' => fn (): StoredTurn => $write('First question'),
                'second' => fn (): StoredTurn => $write('Second question'),
                'barrier' => function () use ($arrived, $release): void {
                    $this->assertTrue($arrived->pop(5));
                    $this->assertTrue($arrived->pop(5));
                    $this->assertTrue($release->push(true, 5));
                    $this->assertTrue($release->push(true, 5));
                },
            ]);
        } finally {
            $arrived->close();
            $release->close();
        }

        $this->assertCount(2, array_unique($sessionIds));
        $this->assertDatabaseCount('agent_conversation_messages', 4);
        foreach (['first', 'second'] as $key) {
            $this->assertDatabaseHas('agent_conversation_messages', ['id' => $results[$key]->userMessageId, 'conversation_id' => $conversationId, 'role' => 'user']);
            $this->assertDatabaseHas('agent_conversation_messages', ['id' => $results[$key]->assistantMessageId, 'conversation_id' => $conversationId, 'role' => 'assistant']);
        }
        $this->assertNotSame('2020-01-01 00:00:00', DB::table('agent_conversations')->where('id', $conversationId)->value('updated_at'));
    }

    public function testConcurrentResumesAcquireOnlyOneClaim(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation(null, null, 'Approval');
        $prompt = new AgentPrompt(new AnonymousAgent('', [], []), 'Send an email', [], m::mock(TextProvider::class), 'model');
        $response = (new AgentResponse('invocation', '', new TextUsage, new Meta('test', 'model')))
            ->withSteps(collect([
                new Step('', [new ToolCall('call-1', 'send_email', [])], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', []),
            ]))->withPendingApprovals(collect([new PendingApproval('call-1', 'send_email', [], 'Sends an email')]));
        $messageId = $store->storeAssistantMessage($conversationId, null, null, $prompt, $response);
        $arrived = new Channel(2);
        $release = new Channel(2);

        $claim = function () use ($store, $conversationId, $arrived, $release): ?ApprovalClaim {
            DB::connection()->beforeExecuting(function (string $query) use ($arrived, $release): void {
                if (str_contains($query, 'for update')) {
                    $this->assertTrue($arrived->push(true, 5));
                    $this->assertTrue($release->pop(5));
                }
            });

            return $store->claimPendingApprovals($conversationId, ['call-1']);
        };

        try {
            $results = parallel([
                'first' => $claim,
                'second' => $claim,
                'barrier' => function () use ($arrived, $release): void {
                    $this->assertTrue($arrived->pop(5));
                    $this->assertTrue($arrived->pop(5));
                    $this->assertTrue($release->push(true, 5));
                    $this->assertTrue($release->push(true, 5));
                },
            ]);
        } finally {
            $arrived->close();
            $release->close();
        }

        $claims = array_values(array_filter([$results['first'], $results['second']]));
        $this->assertCount(1, $claims);
        $this->assertSame($messageId, $claims[0]->messageId);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $messageId, 'approval_claim' => $claims[0]->token]);
    }
}
