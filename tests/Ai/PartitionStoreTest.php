<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Hypervel\Ai\Ai;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\QueryException;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use LogicException;
use Mockery as m;

#[WithConfig('database.connections.testing.foreign_key_constraints', true)]
class PartitionStoreTest extends TestCase
{
    use RefreshDatabase;

    private const string ACCOUNT_A = '00000000-0000-4000-8000-000000000001';

    private const string ACCOUNT_B = '00000000-0000-4000-8000-000000000002';

    /**
     * Use the application-owned partitioned schema.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
    }

    /**
     * Resolve account ownership from the current coroutine.
     */
    protected function setUpInCoroutine(): void
    {
        Ai::resolveConversationPartitionUsing('account_id', static fn (): ?string => CoroutineContext::get('ai-test.account'));
        CoroutineContext::set('ai-test.account', self::ACCOUNT_A);
    }

    public function testStoreReadsAndWritesUseTheCurrentPartition(): void
    {
        $store = new DatabaseConversationStore;
        $conversationA = (string) Str::uuid7();
        $store->storeTurn($conversationA, 'Account A', 'user', 1, $this->prompt(), $this->response());
        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);
        $conversationB = (string) Str::uuid7();
        $store->storeTurn($conversationB, 'Account B', 'user', 1, $this->prompt(), $this->response());

        $this->assertSame($conversationB, $store->latestConversationId('user', 1, AnonymousAgent::class));
        $this->assertFalse($store->conversationBelongsTo($conversationA, 'user', 1));
        $this->assertTrue($store->conversationBelongsTo($conversationB, 'user', 1));
        $this->assertEmpty($store->getLatestConversationMessages($conversationA, 10));
        $this->assertSame([], $store->paginateConversationMessages($conversationA)->items());
        $this->assertCount(2, $store->getLatestConversationMessages($conversationB, 10));
        $this->assertSame([self::ACCOUNT_B], DB::table('agent_conversation_messages')->where('conversation_id', $conversationB)->distinct()->pluck('account_id')->all());

        CoroutineContext::set('ai-test.account', self::ACCOUNT_A);
        $this->assertSame($conversationA, $store->latestConversationId('user', 1, AnonymousAgent::class));
        $this->assertCount(2, $store->paginateConversationMessages($conversationA)->items());
    }

    public function testAnotherPartitionCannotClaimRecordOrSettleAPausedTurn(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Approval');
        $response = $this->response()->withSteps(collect([
            new Step('', [new ToolCall('call-1', 'send_email', [])], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', []),
        ]))->withPendingApprovals(collect([new PendingApproval('call-1', 'send_email', [], 'Sends an email')]));
        $messageId = $store->storeAssistantMessage($conversationId, 'user', 1, $this->prompt(), $response);

        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);
        $this->assertSame([], $store->pendingApprovalsFor($conversationId));
        $this->assertNull($store->claimPendingApprovals($conversationId, ['call-1']));

        CoroutineContext::set('ai-test.account', self::ACCOUNT_A);
        $prompt = $this->prompt(Decisions::from(['call-1' => true]));
        $context = new RunContext('invocation', $prompt->agent, $prompt->provider(), 'model', $this->app->make(Dispatcher::class), approvalStore: $store, conversationId: $conversationId);
        $context->claimPendingApprovals(['call-1']);
        $prompt->setRunContext($context);
        $before = DB::table('agent_conversation_messages')->where('id', $messageId)->first();

        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);
        try {
            $store->recordApprovalResult($prompt->approvalClaim(), new ToolResult('call-1', 'send_email', [], 'Sent'));
            $this->fail('Expected another partition to be unable to record an outcome.');
        } catch (ApprovalMismatchException) {
            $this->assertEquals($before, DB::table('agent_conversation_messages')->where('id', $messageId)->first());
        }

        try {
            $store->storeTurn($conversationId, null, 'user', 1, $prompt, $this->response());
            $this->fail('Expected another partition to be unable to settle the claim.');
        } catch (ApprovalMismatchException) {
            $this->assertEquals($before, DB::table('agent_conversation_messages')->where('id', $messageId)->first());
        }

        CoroutineContext::set('ai-test.account', self::ACCOUNT_A);
        $store->recordApprovalResult($prompt->approvalClaim(), new ToolResult('call-1', 'send_email', [], 'Sent'));
        $this->assertSame($messageId, $store->storeTurn($conversationId, null, 'user', 1, $prompt, $this->response())->assistantMessageId);
    }

    public function testAnUnresolvedPartitionCannotReadTheStore(): void
    {
        CoroutineContext::forget('ai-test.account');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('could not be resolved');
        (new DatabaseConversationStore)->getLatestConversationMessages('conversation', 10);
    }

    public function testATurnCannotAppendToAnotherPartitionsConversation(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Account A');
        CoroutineContext::set('ai-test.account', self::ACCOUNT_B);

        try {
            $store->storeTurn($conversationId, null, 'user', 1, $this->prompt(), $this->response());
            $this->fail('Expected the same-partition foreign key to reject the turn.');
        } catch (QueryException) {
            $this->assertDatabaseCount('agent_conversation_messages', 0);
        }
    }

    /**
     * Build an operation without contacting a provider.
     */
    private function prompt(?Decisions $decisions = null): AgentPrompt
    {
        return new AgentPrompt(new AnonymousAgent('', [], []), 'Hello', [], m::mock(TextProvider::class), 'model', approvalDecisions: $decisions);
    }

    /**
     * Build a response for storage.
     */
    private function response(): AgentResponse
    {
        return new AgentResponse('invocation', 'Hello back', new TextUsage, new Meta('test', 'model'));
    }
}
