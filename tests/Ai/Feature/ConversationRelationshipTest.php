<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Concerns\HasConversations;
use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Models\ConversationMessage;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\TestCase;

#[WithConfig('database.connections.testing.foreign_key_constraints', true)]
class ConversationRelationshipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create the participant table inside the test's database transaction.
     */
    protected function setUpInCoroutine(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function testDefaultTableNamesMatchTheShippedConfiguration(): void
    {
        $this->assertSame(Conversation::DEFAULT_TABLE, config('ai.conversations.tables.conversations'));
        $this->assertSame(ConversationMessage::DEFAULT_TABLE, config('ai.conversations.tables.messages'));
    }

    public function testModelCanRetrieveConversationsUsingRelationship(): void
    {
        $user = ConversationRelationshipUser::create(['name' => 'Taylor']);
        $otherUser = ConversationRelationshipUser::create(['name' => 'Abigail']);

        DB::table('agent_conversations')->insert([
            [
                'id' => 'conversation-1',
                'participant_type' => $user->getMorphClass(),
                'participant_id' => $user->id,
                'title' => 'First Conversation',
                'created_at' => now()->subMinutes(10),
                'updated_at' => now()->subMinutes(10),
            ],
            [
                'id' => 'conversation-2',
                'participant_type' => $user->getMorphClass(),
                'participant_id' => $user->id,
                'title' => 'Second Conversation',
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ],
            [
                'id' => 'conversation-3',
                'participant_type' => $otherUser->getMorphClass(),
                'participant_id' => $otherUser->id,
                'title' => 'Other Conversation',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'conversation-4',
                'participant_type' => 'admin',
                'participant_id' => $user->id,
                'title' => 'Colliding Admin Conversation',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $conversations = $user->conversations()->latest('updated_at')->get();

        $this->assertCount(2, $conversations);
        $this->assertSame(['conversation-2', 'conversation-1'], $conversations->pluck('id')->all());
        $this->assertInstanceOf(Conversation::class, $conversations->first());
    }

    public function testConversationCanRetrieveMessagesUsingRelationship(): void
    {
        $user = ConversationRelationshipUser::create(['name' => 'Taylor']);

        $conversation = Conversation::create([
            'id' => 'conversation-1',
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'Conversation',
        ]);

        DB::table('agent_conversation_messages')->insert([
            [
                'id' => 'message-1',
                'conversation_id' => $conversation->id,
                'participant_type' => $user->getMorphClass(),
                'participant_id' => $user->id,
                'agent' => 'Agent',
                'role' => 'user',
                'content' => 'Hello',
                'attachments' => '[]',
                'steps' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'status' => MessageStatus::Completed,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertCount(1, $conversation->messages);
        $this->assertSame('Hello', $conversation->messages->first()->content);
        $this->assertIsArray($conversation->messages->first()->attachments);
    }

    public function testMessageToolCallsAndResultsFlattenAcrossStepsInOrderAndSerializeWithTheModel(): void
    {
        $user = ConversationRelationshipUser::create(['name' => 'Taylor']);

        $conversation = Conversation::create([
            'id' => 'conversation-1',
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'Conversation',
        ]);

        DB::table('agent_conversation_messages')->insert([
            'id' => 'message-1',
            'conversation_id' => $conversation->id,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'agent' => 'Agent',
            'role' => 'assistant',
            'content' => 'Done',
            'attachments' => '[]',
            'steps' => json_encode([
                ['tool_calls' => [['id' => 'call-1', 'name' => 'a', 'arguments' => [], 'result' => 'x', 'reasoning_encrypted_content' => 'gAAAAA']], 'replay_blocks' => []],
                ['tool_calls' => [['id' => 'call-2', 'name' => 'b', 'arguments' => [], 'result' => 'y']], 'replay_blocks' => []],
            ]),
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message = $conversation->messages->first();

        $this->assertSame(['call-1', 'call-2'], array_column($message->tool_calls, 'id'));
        $this->assertSame([
            ['id' => 'call-1', 'name' => 'a', 'arguments' => [], 'result' => 'x'],
            ['id' => 'call-2', 'name' => 'b', 'arguments' => [], 'result' => 'y'],
        ], $message->tool_results);
        $this->assertArrayHasKey('tool_calls', $message->toArray());
        $this->assertArrayHasKey('tool_results', $message->toArray());
    }

    public function testConversationCanRetrieveItsParticipantUsingRelationship(): void
    {
        $user = ConversationRelationshipUser::create(['name' => 'Taylor']);

        $conversation = Conversation::create([
            'id' => 'conversation-1',
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'Conversation',
        ]);

        $this->assertInstanceOf(ConversationRelationshipUser::class, $conversation->participant);
        $this->assertTrue($conversation->participant->is($user));
    }

    public function testDeletingAConversationRemovesItsMessages(): void
    {
        $conversation = Conversation::create(['id' => (string) Str::uuid7(), 'title' => 'Conversation']);
        $message = $conversation->messages()->create([
            'id' => (string) Str::uuid7(),
            'agent' => 'Agent',
            'role' => 'user',
            'content' => 'Hello',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $conversation->delete();

        $this->assertFalse(DB::table(ConversationMessage::DEFAULT_TABLE)->where('id', $message->id)->exists());
    }

    public function testConversationModelUsesConfiguredDatabaseConnection(): void
    {
        config(['database.connections.secondary' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        Schema::connection('secondary')->create('agent_conversations', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('participant_type');
            $table->string('participant_id');
            $table->string('title');
            $table->timestamps();
        });

        config(['ai.conversations.connection' => 'secondary']);

        DB::connection('secondary')->table('agent_conversations')->insert([
            'id' => 'secondary-conversation-1',
            'participant_type' => ConversationRelationshipUser::class,
            'participant_id' => 1,
            'title' => 'On Secondary DB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $conversation = Conversation::find('secondary-conversation-1');

        $this->assertNotNull($conversation);
        $this->assertSame('On Secondary DB', $conversation->title);
        $this->assertFalse(DB::table('agent_conversations')->where('id', 'secondary-conversation-1')->exists());
    }
}

class ConversationRelationshipUser extends Model
{
    use HasConversations;

    protected ?string $table = 'users';

    protected array $guarded = [];
}
