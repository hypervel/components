<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Models\ConversationMessage;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\QueryException;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

#[WithConfig('database.connections.testing.foreign_key_constraints', true)]
class PartitionModelTest extends TestCase
{
    use RefreshDatabase;

    private const string ACCOUNT_A = '00000000-0000-4000-8000-000000000001';

    private const string ACCOUNT_B = '00000000-0000-4000-8000-000000000002';

    /**
     * Use an application-owned partitioned schema.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
    }

    /**
     * Resolve the partition from the current test coroutine.
     */
    protected function setUpInCoroutine(): void
    {
        Ai::resolveConversationPartitionUsing('account_id', static fn (): ?string => CoroutineContext::get('ai-test.account'));
        CoroutineContext::set('ai-test.account', self::ACCOUNT_A);
    }

    public function testQueriesAndRelationshipsStayWithinTheirPartition(): void
    {
        $conversationA = $this->conversation();
        $messageA = $this->message($conversationA);
        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);
        $conversationB = $this->conversation();
        $messageB = $this->message($conversationB);

        $this->assertSame([$conversationB->id], Conversation::query()->pluck('id')->all());
        $this->assertSame([$messageB->id], ConversationMessage::query()->pluck('id')->all());
        $this->assertSame([$messageB->id], $conversationB->messages()->pluck('id')->all());
        $this->assertTrue($messageB->conversation->is($conversationB));

        CoroutineContext::set('ai-test.account', self::ACCOUNT_A);
        $this->assertSame([$messageA->id], $conversationA->messages()->pluck('id')->all());
        $this->assertTrue($messageA->conversation->is($conversationA));
    }

    #[DataProvider('existingModelOperations')]
    public function testExistingModelsCannotBeUsedInAnotherPartition(string $operation): void
    {
        $conversation = $this->conversation();
        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);
        $conversation->title = 'Changed';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not belong to the current conversation partition');

        $conversation->{$operation}();
    }

    /**
     * Exercise model writes, unscoped refreshes and both relationship entry points.
     */
    public static function existingModelOperations(): array
    {
        return [['save'], ['delete'], ['refresh'], ['messages'], ['participant']];
    }

    public function testAStoredMessageCannotLoadItsConversationInAnotherPartition(): void
    {
        $message = $this->message($this->conversation());
        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);

        $this->expectException(LogicException::class);
        $message->conversation();
    }

    public function testPartitionCannotBeChangedOnAnExistingModel(): void
    {
        $conversation = $this->conversation();
        $conversation->account_id = self::ACCOUNT_B;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be changed');
        $conversation->save();
    }

    public function testRestorationQueriesKeepThePartitionWithoutGlobalScopes(): void
    {
        $conversation = $this->conversation();
        $message = $this->message($conversation);
        $this->assertTrue((new Conversation)->newQueryForRestoration($conversation->id)->first()->is($conversation));

        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);

        $this->assertNull((new Conversation)->newQueryForRestoration($conversation->id)->first());
        $this->assertNull((new ConversationMessage)->newQueryForRestoration([$message->id])->first());
    }

    public function testBothInsertPathsStampBeforeCreatingListeners(): void
    {
        $partitions = [];
        Conversation::creating(function (Conversation $conversation) use (&$partitions): void {
            $partitions[] = $conversation->account_id;
        });
        $this->conversation();
        $conversation = new Conversation(['id' => (string) Str::uuid7(), 'title' => 'Inserted with saveOrIgnore']);

        $this->assertTrue($conversation->saveOrIgnore());
        $this->assertSame([self::ACCOUNT_A, self::ACCOUNT_A], $partitions);
        $this->assertSame(self::ACCOUNT_A, $conversation->fresh()->account_id);
    }

    public function testCreatingListenerCannotReplaceThePartition(): void
    {
        Conversation::creating(static function (Conversation $conversation): void {
            $conversation->account_id = self::ACCOUNT_B;
        });

        $this->expectException(LogicException::class);
        $this->conversation();
    }

    public function testCreateRejectsAConflictingPartitionAttribute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not belong to the current conversation partition');

        Conversation::create([
            'id' => (string) Str::uuid7(),
            'title' => 'Another account',
            'account_id' => self::ACCOUNT_B,
        ]);
    }

    public function testCreatedListenerCanUseRelationshipsAndUpdateTheConversation(): void
    {
        Conversation::created(static function (Conversation $conversation): void {
            $conversation->messages()->create([
                'id' => (string) Str::uuid7(),
                'agent' => 'Agent',
                'role' => 'assistant',
                'content' => 'Welcome',
                'attachments' => [],
                'steps' => [],
                'usage' => [],
                'meta' => [],
                'status' => MessageStatus::Completed,
            ]);
            $conversation->update(['title' => 'Welcome conversation']);
        });

        $conversation = $this->conversation()->fresh();

        $this->assertSame('Welcome conversation', $conversation->title);
        $this->assertSame('Welcome', $conversation->messages->sole()->content);
        $this->assertSame(self::ACCOUNT_A, $conversation->messages->sole()->account_id);
    }

    public function testAnUnresolvedPartitionFailsClosed(): void
    {
        CoroutineContext::forget('ai-test.account');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('could not be resolved');
        Conversation::query()->get();
    }

    public function testQueryUpdatesAndDeletesOnlyAffectTheCurrentPartition(): void
    {
        $conversationA = $this->conversation();
        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);
        $conversationB = $this->conversation();
        $messageB = $this->message($conversationB);

        $this->assertSame(1, Conversation::query()->update(['title' => 'Changed']));
        $this->assertSame('Conversation', DB::table(Conversation::DEFAULT_TABLE)->where('id', $conversationA->id)->value('title'));
        $this->assertSame(1, Conversation::query()->delete());
        $this->assertFalse(DB::table(ConversationMessage::DEFAULT_TABLE)->where('id', $messageB->id)->exists());
        $this->assertTrue(DB::table(Conversation::DEFAULT_TABLE)->where('id', $conversationA->id)->exists());
    }

    public function testMessagesCannotReferenceAnotherPartitionsConversation(): void
    {
        $conversation = $this->conversation();
        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);

        $this->expectException(QueryException::class);
        $this->message($conversation);
    }

    /**
     * Create a conversation owned by the current partition.
     */
    private function conversation(): Conversation
    {
        return Conversation::create(['id' => (string) Str::uuid7(), 'title' => 'Conversation']);
    }

    /**
     * Create a stored message through the model's ordinary insert path.
     */
    private function message(Conversation $conversation): ConversationMessage
    {
        return ConversationMessage::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'agent' => 'Agent',
            'role' => 'assistant',
            'content' => 'Hello',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);
    }
}
