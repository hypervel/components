<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Storage;

use Carbon\CarbonInterface;
use Closure;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Approvals\ApprovalClaim;
use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Contracts\PaginatesConversations;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\ResolvesPendingApprovals;
use Hypervel\Ai\Contracts\VerifiesConversationOwnership;
use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\StoredDocument;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Models\Conversation;
use Hypervel\Ai\Models\ConversationMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\ProviderToolCall;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Ai\Storage\StoredMessage;
use Hypervel\Ai\Streaming\Events\Citation as CitationEvent;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Pagination\CursorPaginator;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

#[WithConfig('database.connections.testing.foreign_key_constraints', true)]
class DatabaseConversationStoreTest extends TestCase
{
    use RefreshDatabase;

    public function testItWritesConversationsToTheDefaultTables(): void
    {
        $store = new DatabaseConversationStore;

        $conversationId = $store->storeConversation('user', 1, 'Hello');

        $this->assertTrue(DB::table('agent_conversations')->where('id', $conversationId)->where('title', 'Hello')->exists());
    }

    public function testItWritesToOverriddenTableNamesFromConfig(): void
    {
        Config::set('ai.conversations.tables.conversations', 'custom_conversations');
        Config::set('ai.conversations.tables.messages', 'custom_conversation_messages');

        createConversationSchema();

        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Hello');

        $this->assertTrue(DB::table('custom_conversations')->where('id', $conversationId)->exists());
        $this->assertFalse(DB::table('agent_conversations')->where('id', $conversationId)->exists());
    }

    public function testItRoutesQueriesThroughTheConfiguredConnection(): void
    {
        Config::set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        createConversationSchema('secondary');

        $store = new DatabaseConversationStore('secondary');
        $conversationId = $store->storeConversation('user', 1, 'Hello');

        $this->assertTrue(DB::connection('secondary')->table('agent_conversations')->where('id', $conversationId)->exists());
        $this->assertFalse(DB::table('agent_conversations')->where('id', $conversationId)->exists());
    }

    public function testItPaginatesConversationMessagesNewestFirstScopedToTheConversation(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Target transcript');
        $otherConversationId = $store->storeConversation('user', 1, 'Other transcript');

        insertStoredConversationMessages($conversationId, ['message-001', 'message-003', 'message-005']);
        insertStoredConversationMessages($otherConversationId, ['message-002', 'message-004']);

        $page = $store->paginateConversationMessages($conversationId, 10);

        $this->assertInstanceOf(PaginatesConversations::class, $store);
        $this->assertInstanceOf(CursorPaginator::class, $page);
        $this->assertContainsOnlyInstancesOf(StoredMessage::class, $page->items());
        $this->assertSame(['message-005', 'message-003', 'message-001'], collect($page->items())->pluck('id')->all());
    }

    public function testItVerifiesWhichParticipantAConversationWasStoredFor(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Mine');

        $this->assertInstanceOf(VerifiesConversationOwnership::class, $store);
        $this->assertTrue($store->conversationBelongsTo($conversationId, 'user', 1));
        $this->assertFalse($store->conversationBelongsTo($conversationId, 'user', 2));
        $this->assertFalse($store->conversationBelongsTo($conversationId, 'team', 1));
    }

    public function testItComparesAParticipantKeyStoredAsAStringAgainstAnInteger(): void
    {
        $store = new DatabaseConversationStore;

        // The key comes from another table, so the column it was written to and the value a caller holds need not agree on type...
        $this->assertTrue($store->conversationBelongsTo($store->storeConversation('user', '7', 'Mine'), 'user', 7));
        $this->assertTrue($store->conversationBelongsTo($store->storeConversation('user', 7, 'Mine'), 'user', '7'));
    }

    public function testItRefusesAHalfMatchingParticipant(): void
    {
        $store = new DatabaseConversationStore;
        $ownerless = $store->storeConversation(null, null, 'Ownerless');
        $owned = $store->storeConversation('user', 1, 'Owned');

        // Filtering the pair in the query instead would answer this first one yes, since a null id nulls both columns and drops the type that was asked about...
        $this->assertFalse($store->conversationBelongsTo($ownerless, 'user', null));
        $this->assertFalse($store->conversationBelongsTo($ownerless, null, 1));
        $this->assertFalse($store->conversationBelongsTo($owned, 'user', null));
        $this->assertFalse($store->conversationBelongsTo($owned, null, 1));
    }

    public function testItRefusesAConversationThatDoesNotExist(): void
    {
        $this->assertFalse((new DatabaseConversationStore)->conversationBelongsTo('missing-conversation', 'user', 1));
    }

    public function testItMatchesAnOwnerlessConversationOnlyToANullParticipant(): void
    {
        $store = new DatabaseConversationStore;
        $ownerless = $store->storeConversation(null, null, 'Ownerless');

        // A turn that pauses for approval is remembered whether or not the agent was given a participant, so a conversation belonging to nobody is a stored state rather than an edge case...
        $this->assertTrue($store->conversationBelongsTo($ownerless, null, null));
        $this->assertFalse($store->conversationBelongsTo($ownerless, 'user', 1));
    }

    public function testItDecodesTheStoredJsonColumns(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Transcript');

        // The JSON columns are why this returns a value object: a caller rendering a transcript should not be decoding storage...
        DB::table('agent_conversation_messages')->insert([
            ...storedConversationMessageAttributes('message-001', $conversationId, 'Saved the note.'),
            'role' => 'assistant',
            'meta' => json_encode(['provider' => 'openai', 'citations' => [['url' => 'https://hypervel.org']]]),
            'steps' => json_encode([assistantStep([['id' => 'call-1', 'name' => 'save_note', 'arguments' => ['a' => 1]]])]),
            'usage' => json_encode(['input_tokens' => 12]),
        ]);

        $message = $store->paginateConversationMessages($conversationId, 1)->items()[0];

        $this->assertSame('openai', $message->meta['provider']);
        $this->assertSame('https://hypervel.org', $message->meta['citations'][0]['url']);
        $this->assertSame('save_note', $message->toolCalls()[0]['name']);
        $this->assertSame(12, $message->usage['input_tokens']);
        $this->assertSame([], $message->toolResults());
        $this->assertSame(MessageStatus::Completed, $message->status);
        $this->assertInstanceOf(CarbonInterface::class, $message->createdAt);
    }

    public function testAStoredToolResultIsNarrowedToItsOwnKeysSoProviderReplayStateStaysOutOfARenderedTranscript(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Transcript');

        DB::table('agent_conversation_messages')->insert([
            ...storedConversationMessageAttributes('message-001', $conversationId, 'Saved the note.'),
            'role' => 'assistant',
            'steps' => json_encode([assistantStep(
                [['id' => 'call-1', 'name' => 'save_note', 'arguments' => ['a' => 1], 'reasoning_id' => 'rs_1', 'reasoning_encrypted_content' => 'gAAAAA']],
                [['id' => 'call-1', 'result' => 'Saved']],
            )]),
        ]);

        $message = $store->paginateConversationMessages($conversationId, 1)->items()[0];

        $this->assertSame([
            ['id' => 'call-1', 'name' => 'save_note', 'arguments' => ['a' => 1], 'result' => 'Saved'],
        ], $message->toolResults());
        $this->assertArrayHasKey('reasoning_encrypted_content', $message->toolCalls()[0]);
    }

    public function testItAdvancesToTheNextCursorPage(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Transcript');

        insertStoredConversationMessages($conversationId, ['message-001', 'message-002', 'message-003', 'message-004', 'message-005']);

        $firstPage = $store->paginateConversationMessages($conversationId, 2);

        RequestContext::set(Request::create('/', 'GET', ['cursor' => $firstPage->nextCursor()?->encode()]));

        $secondPage = $store->paginateConversationMessages($conversationId, 2);

        $this->assertSame(['message-005', 'message-004'], collect($firstPage->items())->pluck('id')->all());
        $this->assertSame(['message-003', 'message-002'], collect($secondPage->items())->pluck('id')->all());
    }

    public function testItReadsTheCursorFromTheGivenQueryParameterName(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Transcript');

        insertStoredConversationMessages($conversationId, ['message-001', 'message-002', 'message-003', 'message-004', 'message-005']);

        $firstPage = $store->paginateConversationMessages($conversationId, 2, 'support');

        // Two transcripts on one page would otherwise page in lockstep, both reading `?cursor=`...
        RequestContext::set(Request::create('/', 'GET', ['support' => $firstPage->nextCursor()?->encode(), 'cursor' => 'ignored']));

        $secondPage = $store->paginateConversationMessages($conversationId, 2, 'support');

        $this->assertSame(['message-003', 'message-002'], collect($secondPage->items())->pluck('id')->all());
    }

    public function testItAcceptsACursorPassedDirectlyWithoutARequest(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Transcript');

        insertStoredConversationMessages($conversationId, ['message-001', 'message-002', 'message-003', 'message-004', 'message-005']);

        $firstPage = $store->paginateConversationMessages($conversationId, 2);

        $secondPage = $store->paginateConversationMessages($conversationId, 2, cursor: $firstPage->nextCursor());

        $this->assertSame(['message-003', 'message-002'], collect($secondPage->items())->pluck('id')->all());
    }

    public function testItReportsTheToolCallsAPausedTurnIsWaitingOn(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Paused');

        insertPausedConversationTurn($conversationId, 'message-001', [
            ['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'a.txt']],
            ['id' => 'call-2', 'name' => 'SendEmail', 'arguments' => ['to' => 'a@b.test']],
        ], ['call-1' => 'Deletes a file.', 'call-2' => null]);

        $pending = $store->pendingApprovalsFor($conversationId);

        $this->assertInstanceOf(ResolvesPendingApprovals::class, $store);
        $this->assertCount(2, $pending);
        $this->assertInstanceOf(PendingApproval::class, $pending[0]);
        $this->assertSame('call-1', $pending[0]->id);
        $this->assertSame('DeleteFile', $pending[0]->tool);
        $this->assertSame(['path' => 'a.txt'], $pending[0]->arguments);
        $this->assertSame('Deletes a file.', $pending[0]->reason);
        $this->assertNull($pending[1]->reason);
    }

    public function testItDropsACallThatAlreadyHasAResult(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Resumed');

        $calls = [
            ['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []],
            ['id' => 'call-2', 'name' => 'SendEmail', 'arguments' => []],
        ];

        insertAssistantTurn($conversationId, 'message-001', 'Waiting on you.', [
            assistantStep($calls, [['id' => 'call-1', 'name' => 'DeleteFile', 'result' => 'Deleted.']]),
        ], ['call-1' => null, 'call-2' => null]);

        $this->assertSame(['call-2'], collect($store->pendingApprovalsFor($conversationId))->pluck('id')->all());
    }

    public function testItReportsNothingWhenTheNewestTurnIsNotPaused(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Answered');

        insertStoredConversationMessages($conversationId, ['message-001']);

        $this->assertSame([], $store->pendingApprovalsFor($conversationId));
        $this->assertSame([], $store->pendingApprovalsFor('missing-conversation'));
    }

    public function testItStoresOneStepPerModelRoundTripFromARememberedAgentPrompt(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(storeTestGeminiInteraction([[
                    'type' => 'function_call',
                    'id' => 'call_123',
                    'name' => 'FixedNumberGenerator',
                    'arguments' => (object) [],
                ]])),
                Http::response(storeTestGeminiInteraction([[
                    'type' => 'model_output',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019']],
                ]])),
            ]),
        ]);

        $user = (object) ['id' => 1];
        $conversationId = (new DatabaseConversationStore)->storeConversation('user', $user->id, 'Tool conversation');

        (new RememberingToolUsingAgent)
            ->continue($conversationId, $user)
            ->prompt('Generate a random number', provider: 'gemini');

        $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

        $this->assertSame('[]', DB::table('agent_conversation_messages')->where('role', 'user')->value('steps'));
        $this->assertSame('The number is 72019', $record->content);
        $steps = json_decode($record->steps, true);
        $this->assertCount(2, $steps);
        $this->assertSame([], $steps[0]['replay_blocks']);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertSame('call_123', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame('FixedNumberGenerator', $steps[0]['tool_calls'][0]['name']);
        $this->assertSame('72019', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame([], $steps[1]['tool_calls']);
        $this->assertSame([], $steps[1]['replay_blocks']);
    }

    public function testItPreservesTheGeminiThoughtSignatureAcrossAPersistedToolConversation(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(storeTestGeminiInteraction([
                    ['type' => 'thought', 'summary' => [['type' => 'text', 'text' => 'Thinking.']], 'signature' => 'sig_persist_777'],
                    ['type' => 'function_call', 'id' => 'call_123', 'name' => 'FixedNumberGenerator', 'arguments' => (object) []],
                ])),
                Http::response(storeTestGeminiInteraction([[
                    'type' => 'model_output',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019']],
                ]])),
                Http::response(storeTestGeminiInteraction([[
                    'type' => 'model_output',
                    'content' => [['type' => 'text', 'text' => 'The second number is 99']],
                ]])),
            ]),
        ]);

        $user = (object) ['id' => 1];
        $conversationId = (new DatabaseConversationStore)->storeConversation('user', $user->id, 'Tool conversation');

        (new RememberingToolUsingAgent)->continue($conversationId, $user)->prompt('Generate a random number', provider: 'gemini');

        $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

        $this->assertSame('sig_persist_777', json_decode((string) $record->steps, true)[0]['tool_calls'][0]['thought_signature']);

        (new RememberingToolUsingAgent)->continue($conversationId, $user)->prompt('Generate another', provider: 'gemini');

        $recorded = Http::recorded();
        $signatures = array_column(array_filter(
            $recorded[count($recorded) - 1][0]->data()['input'],
            fn (array $step): bool => $step['type'] === 'thought',
        ), 'signature');

        $this->assertSame(['sig_persist_777'], $signatures);
    }

    public function testItStoresAResponseBuiltWithoutStepsAsASingleStepOfLists(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            'Check my order status.',
            [],
            m::mock(TextProvider::class),
            'test-model',
        );

        $response = new AgentResponse('invocation-id', 'The order has shipped.', new TextUsage, new Meta);
        $response->toolCalls = collect([
            2 => new ToolCall('call-1', 'lookup_order', ['id' => 1]),
            8 => new ToolCall('call-2', 'lookup_carrier', ['id' => 1]),
        ]);
        $response->toolResults = collect([
            2 => new ToolResult('call-1', 'lookup_order', ['id' => 1], ['status' => 'shipped']),
            8 => new ToolResult('call-2', 'lookup_carrier', ['id' => 1], ['carrier' => 'UPS']),
        ]);

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $steps = DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps');

        $steps = json_decode($steps, true);
        $this->assertCount(1, $steps);
        $this->assertTrue(array_is_list($steps[0]['tool_calls']));
        $this->assertCount(2, $steps[0]['tool_calls']);
        $this->assertSame('call-1', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame(['status' => 'shipped'], $steps[0]['tool_calls'][0]['result']);
        $this->assertSame('call-2', $steps[0]['tool_calls'][1]['id']);
        $this->assertSame(['carrier' => 'UPS'], $steps[0]['tool_calls'][1]['result']);
    }

    public function testItScopesTheLatestConversationLookupToConversationsTheAgentHasParticipatedIn(): void
    {
        $store = new DatabaseConversationStore;

        $first = $store->storeConversation('user', 1, 'First');
        $second = $store->storeConversation('user', 1, 'Second');

        $insertMessage = fn (string $id, string $conversationId, string $agent) => DB::table('agent_conversation_messages')->insert([
            ...storedConversationMessageAttributes($id, $conversationId, 'Hello'),
            'agent' => $agent,
        ]);

        $insertMessage('message-1', $first, ToolUsingAgent::class);
        $insertMessage('message-2', $second, RememberingToolUsingAgent::class);

        $this->assertSame($first, $store->latestConversationId('user', 1, ToolUsingAgent::class));
        $this->assertSame($second, $store->latestConversationId('user', 1, RememberingToolUsingAgent::class));
        $this->assertNull($store->latestConversationId('user', 1, 'App\Agents\Unknown'));

        $insertMessage('message-3', $second, ToolUsingAgent::class);

        $this->assertSame($second, $store->latestConversationId('user', 1, ToolUsingAgent::class));
    }

    public function testItRoundTripsToolResultFailureStatusThroughStorage(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            'Where is Berlin?',
            [],
            m::mock(TextProvider::class),
            'test-model',
        );

        $response = new AgentResponse('invocation-id', '', new TextUsage, new Meta);
        $response->toolCalls = collect([new ToolCall('call-1', 'query-resources', [])]);
        $response->toolResults = collect([
            new ToolResult('call-1', 'query-resources', [], 'Tool not found', failed: true),
        ]);

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $result = $store->getLatestConversationMessages($conversationId, 10)
            ->first(fn (Message $message): bool => $message instanceof ToolResultMessage)
            ?->toolResults
            ->first();

        $this->assertNotNull($result);
        $this->assertFalse($result->successful());
        $this->assertSame('Tool not found', $result->error());
    }

    public function testItStoresAToolResultIdOnTheCallThatMadeIt(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');
        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            'Write the file',
            [],
            m::mock(TextProvider::class),
            'test-model',
        );

        $response = (new AgentResponse('invocation-1', 'Wrote it.', new TextUsage, new Meta('openai', 'gpt-5')))
            ->withSteps(collect([new Step(
                'Wrote it.',
                [new ToolCall('call-1', 'WriteFile', ['path' => 'a.txt', 'contents' => 'alpha'], 'result-1')],
                [new ToolResult('call-1', 'WriteFile', ['path' => 'a.txt', 'contents' => 'alpha'], 'Wrote 5 bytes.', 'result-1')],
                FinishReason::Stop,
                new TextUsage,
                new Meta('openai', 'gpt-5'),
                '',
                [],
            )]));

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $stored = DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps');

        $calls = json_decode($stored, true)[0]['tool_calls'];
        $this->assertCount(1, $calls);
        $this->assertSame([
            'id' => 'call-1',
            'name' => 'WriteFile',
            'arguments' => ['path' => 'a.txt', 'contents' => 'alpha'],
            'result_id' => 'result-1',
            'result' => 'Wrote 5 bytes.',
        ], $calls[0]);

        $result = $store->getLatestConversationMessages($conversationId, 10)
            ->first(fn (Message $message): bool => $message instanceof ToolResultMessage)
            ?->toolResults
            ->first();

        $this->assertSame(['path' => 'a.txt', 'contents' => 'alpha'], $result->arguments);
        $this->assertSame('Wrote 5 bytes.', $result->result);
        $this->assertSame('result-1', $result->resultId);
    }

    public function testItTreatsToolResultsStoredBeforeTheFailedFlagAsSuccessful(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', '', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'query-resources', 'arguments' => []]],
                [['id' => 'call-1', 'name' => 'query-resources', 'arguments' => [], 'result' => 'Berlin', 'result_id' => null]],
            ),
        ]);

        $result = $store->getLatestConversationMessages($conversationId, 10)
            ->first(fn (Message $message): bool => $message instanceof ToolResultMessage)
            ?->toolResults
            ->first();

        $this->assertNotNull($result);
        $this->assertTrue($result->successful());
        $this->assertNull($result->error());
    }

    public function testABareRejectionResumeDoesNotPersistABlankAssistantRow(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

        insertAssistantTurn($conversationId, 'paused-1', '', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]],
                [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => [], 'result' => 'The user rejected this tool call.', 'result_id' => null]],
            ),
        ], []);

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            '',
            [],
            m::mock(TextProvider::class),
            'test-model',
            approvalDecisions: Decisions::from(['call-1' => Decision::reject()]),
        );

        $response = new AgentResponse('invocation-id', '', new TextUsage, new Meta);
        $response->toolResults = collect([
            new ToolResult('call-1', 'DeleteFile', [], 'The user rejected this tool call.'),
        ]);

        $messageId = $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $this->assertSame('paused-1', $messageId);
        $this->assertSame(1, DB::table('agent_conversation_messages')->where('role', 'assistant')->count());
    }

    public function testAResumeFoldsItsStepsTextAndUsageIntoThePausedRow(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

        insertAssistantTurn($conversationId, 'paused-1', '', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]],
                [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => [], 'result' => 'Deleted', 'result_id' => null]],
            ),
        ], []);

        DB::table('agent_conversation_messages')->where('id', 'paused-1')->update(['usage' => json_encode(['input_tokens' => 10, 'output_tokens' => 5])]);

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            '',
            [],
            m::mock(TextProvider::class),
            'test-model',
            approvalDecisions: Decisions::from(['call-1' => true]),
        );

        $response = new AgentResponse('invocation-id', 'Done.', new TextUsage(20, 7), new Meta('openai', 'gpt-5'));
        $response->steps = collect([new Step('Done.', [], [], FinishReason::Stop, new TextUsage(20, 7), new Meta, '', [])]);

        $messageId = $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();

        $this->assertSame('paused-1', $messageId);
        $this->assertSame('Done.', $row->content);
        $steps = json_decode($row->steps, true);
        $this->assertCount(2, $steps);
        $this->assertSame('Done.', $steps[1]['content']);
        $this->assertSame(30, json_decode($row->usage, true)['input_tokens']);
        $this->assertSame(12, json_decode($row->usage, true)['output_tokens']);
        $this->assertSame('openai', json_decode($row->meta, true)['provider']);
        $this->assertSame('gpt-5', json_decode($row->meta, true)['model']);
    }

    public function testAResumeKeepsTheCitationsThePausedHalfOfTheTurnCollected(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

        insertAssistantTurn($conversationId, 'paused-1', '', [
            assistantStep([['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]]),
        ], ['call-1' => 'Deletes a file'], ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'citations' => [(new UrlCitation('https://hypervel.org/docs/ai'))->toArray()]]);

        $prompt = new AgentPrompt(new ToolUsingAgent, '', [], m::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

        $meta = new Meta('anthropic', 'claude-sonnet-4-6', collect([new UrlCitation('https://hypervel.org/docs/mcp')]));

        $response = (new AgentResponse('invocation-id', 'Deleted.', new TextUsage, $meta))->withSteps(collect([
            new Step('Deleted.', [], [], FinishReason::Stop, new TextUsage, $meta, '', []),
        ]));

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $meta = json_decode(DB::table('agent_conversation_messages')->where('id', 'paused-1')->value('meta'), true);
        $this->assertSame('claude-sonnet-4-6', $meta['model']);
        $this->assertCount(2, $meta['citations']);
        $this->assertSame('https://hypervel.org/docs/ai', $meta['citations'][0]['url']);
        $this->assertSame('https://hypervel.org/docs/mcp', $meta['citations'][1]['url']);
    }

    public function testAFoldThatRecordedNoResultDoesNotLeaveTheRowReportingAPendingApproval(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

        insertAssistantTurn($conversationId, 'paused-1', 'Waiting.', [
            assistantStep([['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]]),
        ], ['call-1' => 'Deletes a file']);

        $prompt = new AgentPrompt(new ToolUsingAgent, '', [], m::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

        $response = (new AgentResponse('invocation-id', 'Done.', new TextUsage, new Meta))->withSteps(collect([
            new Step('Done.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', []),
        ]));

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $this->assertSame([], $store->pendingApprovalsFor($conversationId));
    }

    public function testAResumeDoesNotFoldIntoASettledRowOnceANewerPlainTurnFollowsIt(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

        insertAssistantTurn($conversationId, 'paused-1', 'Old.', [assistantStep()], []);
        insertAssistantTurn($conversationId, 'plain-2', 'Hi.', [assistantStep()]);

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            '',
            [],
            m::mock(TextProvider::class),
            'test-model',
            approvalDecisions: Decisions::from(['call-1' => true]),
        );

        $response = new AgentResponse('invocation-id', 'Done.', new TextUsage, new Meta);
        $response->steps = collect([new Step('Done.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', [])]);

        $messageId = $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $this->assertNotContains($messageId, ['paused-1', 'plain-2']);
        $this->assertSame('Old.', DB::table('agent_conversation_messages')->where('id', 'paused-1')->value('content'));
    }

    public function testItReplaysACompletedMultiStepTurnWithEachResultAnsweringItsOwnStep(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', 'Done.', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result_id' => 'result-1']],
                [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a', 'result_id' => 'result-1']],
                content: 'Reading a first.',
            ),
            assistantStep(
                [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]],
                [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result' => 'Deleted b']],
            ),
            assistantStep(),
        ]);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(5, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame('Reading a first.', $messages[0]->content);
        $this->assertCount(1, $messages[0]->toolCalls);
        $this->assertSame('call-1', $messages[0]->toolCalls[0]->id);
        $this->assertSame('result-1', $messages[0]->toolCalls[0]->resultId);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $this->assertCount(1, $messages[1]->toolResults);
        $this->assertSame('call-1', $messages[1]->toolResults[0]->id);
        $this->assertSame('result-1', $messages[1]->toolResults[0]->resultId);
        $this->assertInstanceOf(AssistantMessage::class, $messages[2]);
        $this->assertSame('', $messages[2]->content);
        $this->assertSame(['call-2'], $messages[2]->toolCalls->pluck('id')->all());
        $this->assertInstanceOf(ToolResultMessage::class, $messages[3]);
        $this->assertSame(['call-2'], $messages[3]->toolResults->pluck('id')->all());
        $this->assertInstanceOf(AssistantMessage::class, $messages[4]);
        $this->assertSame('Done.', $messages[4]->content);
        $this->assertEmpty($messages[4]->toolCalls);
    }

    public function testItDropsTheUnexecutedCallsOfAStepLimitedTailButKeepsItsText(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', 'I ran out of steps.', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]]],
                [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']]],
            ),
            assistantStep(
                [['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1]]],
                [],
                [['type' => 'text', 'text' => 'I ran out of steps.'], ['type' => 'tool_use', 'id' => 'call-2']],
            ),
        ], meta: ['provider' => 'anthropic']);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame(['call-1'], $messages[0]->toolCalls->pluck('id')->all());
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $this->assertInstanceOf(AssistantMessage::class, $messages[2]);
        $this->assertSame('I ran out of steps.', $messages[2]->content);
        $this->assertSame([], $messages[2]->replayBlocks);
        $this->assertEmpty($messages[2]->toolCalls);
    }

    public function testItReplaysEveryStepOfAPausedTurnWithItsReplayBlocksTaggedByTheProviderThatMadeThem(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', 'Read a, deleting b', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a']]],
                [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
                replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
            ),
            assistantStep(
                [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]],
                replayBlocks: [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
            ),
        ], ['call-2' => 'Destructive.'], ['provider' => 'anthropic']);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame('anthropic', $messages[0]->replayBlocksProvider);
        $this->assertCount(2, $messages[0]->replayBlocks);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $this->assertSame(['call-1'], $messages[1]->toolResults->pluck('id')->all());
        $this->assertInstanceOf(AssistantMessage::class, $messages[2]);
        $this->assertSame('Read a, deleting b', $messages[2]->content);
        $this->assertSame('anthropic', $messages[2]->replayBlocksProvider);
        $this->assertCount(2, $messages[2]->replayBlocks);
    }

    public function testCompletingATurnStoresNoReplayBlocksAndDropsThoseOfThePausedRowsItResumed(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', '', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result' => 'deleted']],
                replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
            ),
        ], [], ['provider' => 'anthropic']);

        $prompt = new AgentPrompt(new ToolUsingAgent, '', [], m::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

        $response = (new AgentResponse('invocation-id', 'Deleted b.', new TextUsage, new Meta('anthropic')))->withSteps(collect([
            new Step('Deleted b.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'text', 'text' => 'Deleted b.']]),
        ]));

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $blocks = DB::table('agent_conversation_messages')->where('role', 'assistant')->pluck('steps')
            ->flatMap(fn (string $steps) => collect(json_decode($steps, true))->pluck('replay_blocks'));

        $this->assertSame([[], []], $blocks->all());
        $this->assertSame('completed', DB::table('agent_conversation_messages')->where('id', 'message-1')->value('status'));
    }

    public function testATurnThatPausesAgainKeepsTheReplayBlocksOfTheRowsItResumed(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', '', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result' => 'deleted']],
                replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
            ),
        ], [], ['provider' => 'anthropic']);

        $prompt = new AgentPrompt(new ToolUsingAgent, '', [], m::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

        $response = (new AgentResponse('invocation-id', '', new TextUsage, new Meta('anthropic')))
            ->withSteps(collect([
                new Step('', [new ToolCall('call-2', 'DeleteFile', ['path' => 'c'])], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']]),
            ]))
            ->withPendingApprovals(collect([new PendingApproval('call-2', 'DeleteFile', ['path' => 'c'], 'Deletes a file')]));

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $blocks = DB::table('agent_conversation_messages')->where('role', 'assistant')->pluck('steps')
            ->flatMap(fn (string $steps) => collect(json_decode($steps, true))->pluck('replay_blocks'));

        $this->assertEqualsCanonicalizing([
            [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
            [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
        ], $blocks->all());
        $this->assertSame('paused', DB::table('agent_conversation_messages')->where('id', 'message-1')->value('status'));
    }

    public function testAResumeFoldsIntoThePausedRowHoldingItsDecidedCallRatherThanTheNewestPause(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Approval conversation');

        insertAssistantTurn($conversationId, 'paused-1', '', [
            assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'deleted']]),
        ], ['call-1' => 'Deletes a file'], ['provider' => 'anthropic']);

        insertAssistantTurn($conversationId, 'paused-2', '', [
            assistantStep([['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]]),
        ], ['call-2' => 'Deletes b file'], ['provider' => 'anthropic']);

        $prompt = new AgentPrompt(new ToolUsingAgent, '', [], m::mock(TextProvider::class), 'test-model', approvalDecisions: Decisions::from(['call-1' => true]));

        $response = (new AgentResponse('invocation-id', 'Deleted a.', new TextUsage, new Meta('anthropic')))->withSteps(collect([
            new Step('Deleted a.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', []),
        ]));

        $this->assertSame('paused-1', $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response));

        $rows = DB::table('agent_conversation_messages')->where('role', 'assistant')->get()->keyBy('id');

        $this->assertSame('Deleted a.', $rows['paused-1']->content);
        $this->assertSame('completed', $rows['paused-1']->status);
        $this->assertSame('', $rows['paused-2']->content);
        $this->assertSame('paused', $rows['paused-2']->status);
    }

    public function testItSkipsAStepThatHasNothingLeftToSayOnceItsUnexecutedCallsAreDropped(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', '', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]]],
                [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']]],
            ),
            assistantStep([['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1]]]),
        ], meta: ['provider' => 'anthropic']);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(2, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertCount(1, $messages[0]->toolCalls);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
    }

    public function testItReplaysAMultiStepPauseWithEachStepCarryingItsOwnReplayBlocks(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', 'Let me delete b too', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a']]],
                [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
                replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']],
            ),
            assistantStep(
                [['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]],
                [],
                [['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']],
            ),
        ], ['call-2' => null], ['provider' => 'anthropic']);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame([['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1']], $messages[0]->replayBlocks);
        $this->assertSame('anthropic', $messages[0]->replayBlocksProvider);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $this->assertSame(['call-1'], $messages[1]->toolResults->pluck('id')->all());
        $this->assertInstanceOf(AssistantMessage::class, $messages[2]);
        $this->assertSame('Let me delete b too', $messages[2]->content);
        $this->assertSame([['type' => 'thinking', 'signature' => 'sig-2'], ['type' => 'tool_use', 'id' => 'call-2']], $messages[2]->replayBlocks);
        $this->assertSame('anthropic', $messages[2]->replayBlocksProvider);
        $this->assertSame(['call-2'], $messages[2]->toolCalls->pluck('id')->all());
    }

    public function testItKeepsAnExecutedCallAndAPendingCallTogetherOnAMixedPauseStep(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', 'Let me delete b too', [
            assistantStep(
                [
                    ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a']],
                    ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']],
                ],
                [['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a']],
            ),
        ], ['call-2' => null]);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(2, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame('Let me delete b too', $messages[0]->content);
        $this->assertSame(['call-1', 'call-2'], $messages[0]->toolCalls->pluck('id')->all());
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $this->assertSame(['call-1'], $messages[1]->toolResults->pluck('id')->all());
    }

    public function testItWritesTheStepsOfAPausedTurnWithTheirReplayBlocksAndKeepsReplayStateOutOfMeta(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            'Delete config/app.php.',
            [],
            m::mock(TextProvider::class),
            'test-model',
        );

        $response = (new AgentResponse('invocation-id', 'Let me think about that', new TextUsage, new Meta('anthropic')))
            ->withSteps(collect([
                new Step('', [new ToolCall('call-0', 'ReadFile', ['path' => 'a'])], [new ToolResult('call-0', 'ReadFile', ['path' => 'a'], 'contents')], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'tool_use', 'id' => 'call-0']]),
                new Step('Let me think about that', [new ToolCall('call-1', 'DeleteFile', ['path' => 'config/app.php'])], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-1']]),
            ]))
            ->withPendingApprovals(collect([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
            ]));

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

        $steps = json_decode($record->steps, true);
        $this->assertCount(2, $steps);
        $this->assertSame('', $steps[0]['content']);
        $this->assertSame([['type' => 'tool_use', 'id' => 'call-0']], $steps[0]['replay_blocks']);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertSame('call-0', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame('contents', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame('Let me think about that', $steps[1]['content']);
        $this->assertSame([['type' => 'thinking', 'signature' => 'sig-1']], $steps[1]['replay_blocks']);
        $this->assertCount(1, $steps[1]['tool_calls']);
        $this->assertSame('call-1', $steps[1]['tool_calls'][0]['id']);
        $this->assertArrayNotHasKey('result', $steps[1]['tool_calls'][0]);
        $this->assertSame('Deletes a file', $steps[1]['tool_calls'][0]['approval_reason']);
        $this->assertSame(['provider' => 'anthropic', 'model' => null, 'citations' => []], json_decode($record->meta, true));
        $this->assertSame('paused', $record->status);
    }

    public function testItWritesTheStepsAPausedStreamCarriedOnItsApprovalRequest(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            'Delete config/app.php.',
            [],
            m::mock(TextProvider::class),
            'test-model',
        );

        $response = new StreamedAgentResponse('invocation-id', collect([
            new ToolApprovalRequest('event-1', collect([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
            ]), 0, collect([
                new Step('', [new ToolCall('call-1', 'DeleteFile', ['path' => 'config/app.php'])], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'thinking', 'signature' => 'sig-1']]),
            ])),
        ]), new Meta);

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $steps = DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps');

        $steps = json_decode($steps, true);
        $this->assertCount(1, $steps);
        $this->assertSame([['type' => 'thinking', 'signature' => 'sig-1']], $steps[0]['replay_blocks']);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertSame('call-1', $steps[0]['tool_calls'][0]['id']);
        $this->assertArrayNotHasKey('result', $steps[0]['tool_calls'][0]);
    }

    public function testItWritesTheStepsACompletedStreamCarriedOnItsStreamEnd(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            'Read config/app.php.',
            [],
            m::mock(TextProvider::class),
            'test-model',
        );

        $call = new ToolCall('call-1', 'ReadFile', ['path' => 'config/app.php']);

        $response = new StreamedAgentResponse('invocation-id', collect([
            new TextDelta('event-1', 'message-1', 'Done.', 0),
            new StreamEnd('event-2', 'stop', new TextUsage, 0, collect([
                new Step('', [$call], [new ToolResult('call-1', 'ReadFile', ['path' => 'config/app.php'], 'contents')], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'tool_use', 'id' => 'call-1']]),
                new Step('Done.', [], [], FinishReason::Stop, new TextUsage, new Meta, '', [['type' => 'text', 'text' => 'Done.']]),
            ])),
        ]), new Meta);

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $steps = DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps');

        $steps = json_decode($steps, true);
        $this->assertCount(2, $steps);
        $this->assertSame([], $steps[0]['replay_blocks']);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertSame('call-1', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame('contents', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame([], $steps[1]['tool_calls']);
        $this->assertSame([], $steps[1]['replay_blocks']);
    }

    public function testStoringApprovalResultsForAConversationWithNoPausedRowThrows(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        $this->expectException(ApprovalMismatchException::class);
        $this->expectExceptionMessage('The approval results do not match a paused conversation turn.');
        $store->storeApprovalResults($conversationId, [
            new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
        ]);
    }

    public function testAMismatchAgainstAPausedRowCarriesTheApprovalsThatAreActuallyPending(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', '', [
            assistantStep([
                ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']],
                ['id' => 'call-2', 'name' => 'read_file', 'arguments' => ['path' => 'y']],
            ]),
        ], ['call-1' => 'Destructive operation.']);

        try {
            $store->storeApprovalResults($conversationId, [
                new ToolResult('call-9', 'delete_file', ['path' => 'x'], 'Deleted x'),
            ]);

            $this->fail('Expected an approval mismatch.');
        } catch (ApprovalMismatchException $exception) {
            $this->assertSame([
                ['id' => 'call-1', 'tool' => 'delete_file', 'arguments' => ['path' => 'x'], 'reason' => 'Destructive operation.'],
            ], $exception->pendingApprovals->map->toArray()->all());
        }
    }

    public function testResolvingApprovalResultsDoesNotRequireTheResolverToBeThePausedTurnsParticipant(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', '', [
            assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']]]),
        ], ['call-1' => 'Deletes x']);

        DB::table('agent_conversation_messages')->where('id', 'message-1')->update(['participant_id' => 2]);

        $store->storeApprovalResults($conversationId, [
            new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
        ]);

        $row = DB::table('agent_conversation_messages')->where('id', 'message-1')->first();

        $this->assertSame([], $store->pendingApprovalsFor($conversationId));
        $call = json_decode($row->steps, true)[0]['tool_calls'][0];
        $this->assertSame('call-1', $call['id']);
        $this->assertSame('Deleted x', $call['result']);
    }

    public function testResolvingApprovalResultsWritesEachOutcomeIntoTheStepThatMadeTheCall(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', '', [
            assistantStep(
                [['id' => 'call-0', 'name' => 'read_file', 'arguments' => ['path' => 'a']]],
                [['id' => 'call-0', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
            ),
            assistantStep([
                ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']],
                ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'y']],
            ]),
        ], ['call-1' => 'Deletes x', 'call-2' => 'Deletes y']);

        $store->storeApprovalResults($conversationId, [
            new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
        ]);

        $partial = $store->pendingApprovalsFor($conversationId);

        $store->storeApprovalResults($conversationId, [
            new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
            new ToolResult('call-2', 'delete_file', ['path' => 'y'], 'The user rejected this tool call.', denied: true),
        ]);

        $row = DB::table('agent_conversation_messages')->where('id', 'message-1')->first();

        $this->assertCount(1, $partial);
        $this->assertSame('call-2', $partial[0]->id);
        $this->assertSame('Deletes y', $partial[0]->reason);
        $this->assertSame([], $store->pendingApprovalsFor($conversationId));
        $steps = json_decode($row->steps, true);
        $this->assertCount(2, $steps);
        $this->assertSame(['call-0'], array_column($steps[0]['tool_calls'], 'id'));
        $this->assertSame(['call-1', 'call-2'], array_column($steps[1]['tool_calls'], 'id'));
        $this->assertArrayNotHasKey('denied', $steps[1]['tool_calls'][0]);
        $this->assertTrue($steps[1]['tool_calls'][1]['denied']);
    }

    public function testResolvingAnEditedApprovalRecordsTheArgumentsTheToolActuallyRanWith(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', '', [
            assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x']]]),
        ], ['call-1' => 'Deletes x']);

        $store->storeApprovalResults($conversationId, [
            new ToolResult('call-1', 'delete_file', ['path' => 'y'], 'Deleted y'),
        ]);

        $steps = DB::table('agent_conversation_messages')->where('id', 'message-1')->value('steps');

        $calls = json_decode($steps, true)[0]['tool_calls'];
        $this->assertCount(1, $calls);
        $this->assertSame('call-1', $calls[0]['id']);
        $this->assertSame(['path' => 'y'], $calls[0]['arguments']);
        $this->assertSame('Deleted y', $calls[0]['result']);
    }

    public function testItReplaysAResumedPauseAsThePausedCallItsResultThenTheResumeTurn(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', '', [
            assistantStep([['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a']]]),
        ], ['call-1' => null]);

        $store->storeApprovalResults($conversationId, [
            new ToolResult('call-1', 'delete_file', ['path' => 'a'], 'Deleted a'),
        ]);

        insertAssistantTurn($conversationId, 'message-2', 'Let me delete b too', [
            assistantStep([['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b']]]),
        ], ['call-2' => null]);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(3, $messages);
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame(['call-1'], $messages[0]->toolCalls->pluck('id')->all());
        $this->assertInstanceOf(ToolResultMessage::class, $messages[1]);
        $this->assertSame(['Deleted a'], $messages[1]->toolResults->pluck('result')->all());
        $this->assertInstanceOf(AssistantMessage::class, $messages[2]);
        $this->assertSame('Let me delete b too', $messages[2]->content);
        $this->assertSame(['call-2'], $messages[2]->toolCalls->pluck('id')->all());
    }

    public function testItStillRehydratesReasoningEncryptedContentStoredOnLegacyToolCalls(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Reasoning conversation');

        insertAssistantTurn($conversationId, 'message-1', 'Looking that up.', [
            assistantStep(
                [[
                    'id' => 'call-1',
                    'name' => 'lookup_order',
                    'arguments' => ['id' => 1],
                    'reasoning_id' => 'rs_1',
                    'reasoning_summary' => [],
                    'reasoning_encrypted_content' => 'enc-blob-1',
                ]],
                [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']]],
            ),
        ]);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertSame('rs_1', $messages[0]->toolCalls->first()->reasoningId);
        $this->assertSame('enc-blob-1', $messages[0]->toolCalls->first()->reasoningEncryptedContent);
    }

    public function testUserMessagesWithStoredAttachmentsAreRehydratedAsUserMessage(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Attachment conversation');

        DB::table('agent_conversation_messages')->insert([
            'id' => 'message-1',
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => 1,
            'agent' => ToolUsingAgent::class,
            'role' => 'user',
            'content' => 'Describe this image.',
            'attachments' => json_encode([
                ['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg', 'mime' => 'image/jpeg', 'name' => null],
            ]),
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertSame('Describe this image.', $messages[0]->content);
        $this->assertCount(1, $messages[0]->attachments);
        $this->assertInstanceOf(RemoteImage::class, $messages[0]->attachments->first());
        $this->assertSame('https://example.com/photo.jpg', $messages[0]->attachments->first()->url);
    }

    public function testUserMessagesWithMultipleAttachmentTypesAreAllRehydrated(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Multi-attachment conversation');

        DB::table('agent_conversation_messages')->insert([
            'id' => 'message-1',
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => 1,
            'agent' => ToolUsingAgent::class,
            'role' => 'user',
            'content' => 'Analyze these files.',
            'attachments' => json_encode([
                ['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg', 'mime' => 'image/jpeg', 'name' => null],
                ['type' => 'stored-document', 'path' => 'docs/report.pdf', 'disk' => 'local', 'name' => 'report.pdf'],
            ]),
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertInstanceOf(UserMessage::class, $messages[0]);
        $this->assertCount(2, $messages[0]->attachments);
        $this->assertInstanceOf(RemoteImage::class, $messages[0]->attachments[0]);
        $this->assertInstanceOf(StoredDocument::class, $messages[0]->attachments[1]);
        $this->assertSame('docs/report.pdf', $messages[0]->attachments[1]->path);
    }

    public function testUserMessagesWithNoAttachmentsAreReturnedAsPlainMessage(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Plain conversation');

        DB::table('agent_conversation_messages')->insert([
            'id' => 'message-1',
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => 1,
            'agent' => ToolUsingAgent::class,
            'role' => 'user',
            'content' => 'Hello.',
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $messages = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertInstanceOf(Message::class, $messages[0]);
        $this->assertNotInstanceOf(UserMessage::class, $messages[0]);
    }

    public function testMalformedStoredAttachmentJsonFailsLoudly(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Malformed attachment conversation');

        DB::table('agent_conversation_messages')->insert([
            'id' => 'message-1',
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => 1,
            'agent' => ToolUsingAgent::class,
            'role' => 'user',
            'content' => 'Describe this image.',
            'attachments' => json_encode(['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg']),
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stored conversation attachments must be a JSON array.');
        $store->getLatestConversationMessages($conversationId, 10);
    }

    public function testMalformedKnownStoredAttachmentsFailLoudly(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Malformed attachment conversation');

        DB::table('agent_conversation_messages')->insert([
            'id' => 'message-1',
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => 1,
            'agent' => ToolUsingAgent::class,
            'role' => 'user',
            'content' => 'Describe this image.',
            'attachments' => json_encode([
                ['type' => 'remote-image', 'mime' => 'image/jpeg'],
            ]),
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot reconstruct [remote-image] attachment because [url] is missing or invalid.');
        $store->getLatestConversationMessages($conversationId, 10);
    }

    public function testItScopesConversationsByParticipantTypeSoSharedIdsNoLongerCollide(): void
    {
        $store = new DatabaseConversationStore;

        $user = new class {
            public int $id = 1;

            public function getMorphClass(): string
            {
                return 'user';
            }
        };

        $admin = new class {
            public int $id = 1;

            public function getMorphClass(): string
            {
                return 'admin';
            }
        };

        $userConversation = $store->storeConversation('user', $user->id, 'User chat');
        $adminConversation = $store->storeConversation('admin', $admin->id, 'Admin chat');

        $insertMessage = fn (string $id, string $conversationId, string $participantType) => DB::table('agent_conversation_messages')->insert([
            'id' => $id,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => 1,
            'agent' => ToolUsingAgent::class,
            'role' => 'user',
            'content' => 'Hello',
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => MessageStatus::Completed,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $insertMessage('message-user', $userConversation, 'user');
        $insertMessage('message-admin', $adminConversation, 'admin');

        // Despite sharing id 1, each participant only resolves its own conversation...
        $this->assertSame($userConversation, $store->latestConversationId('user', $user->id, ToolUsingAgent::class));
        $this->assertSame($adminConversation, $store->latestConversationId('admin', $admin->id, ToolUsingAgent::class));
        $this->assertNotSame($adminConversation, $userConversation);
    }

    public function testItRecordsTheReasoningAStreamedTurnProducedOntoTheTurnSteps(): void
    {
        Config::set('ai.conversations.generate_title', false);

        $chunk = fn (array $delta, ?string $finishReason = null): string => 'data: ' . json_encode([
            'id' => 'chatcmpl-reasoner-1',
            'object' => 'chat.completion.chunk',
            'model' => 'deepseek-reasoner',
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        ]);

        Http::fake(['api.deepseek.com/*' => Http::response(
            body: implode("\n\n", [
                $chunk(['role' => 'assistant', 'reasoning_content' => 'They want ']),
                $chunk(['reasoning_content' => 'the temperature.']),
                $chunk(['content' => 'It is 12°C.']),
                $chunk([], 'stop'),
                'data: [DONE]',
            ]) . "\n\n",
            headers: ['Content-Type' => 'text/event-stream'],
        )]);

        $response = (new RememberingAssistantAgent)
            ->forUser((object) ['id' => 1])
            ->stream('How cold is it?', provider: 'deepseek', model: 'deepseek-reasoner');

        foreach ($response as $event);

        $record = DB::table('agent_conversation_messages')
            ->where('conversation_id', $response->conversationId)
            ->where('role', 'assistant')
            ->first();

        $steps = json_decode((string) $record->steps, true);
        $this->assertCount(1, $steps);
        $this->assertSame('It is 12°C.', $steps[0]['content']);
        $this->assertSame('They want the temperature.', $steps[0]['reasoning']);
        $this->assertArrayNotHasKey('reasoning', json_decode((string) $record->meta, true));
    }

    public function testItRecordsTheReasoningAPromptedTurnProducedOntoTheTurnSteps(): void
    {
        Config::set('ai.conversations.generate_title', false);

        Http::fake(['api.deepseek.com/*' => Http::response([
            'id' => 'chatcmpl-reasoner-1',
            'object' => 'chat.completion',
            'model' => 'deepseek-reasoner',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'reasoning_content' => 'They want the temperature.',
                    'content' => 'It is 12°C.',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])]);

        $response = (new RememberingAssistantAgent)
            ->forUser((object) ['id' => 1])
            ->prompt('How cold is it?', provider: 'deepseek', model: 'deepseek-reasoner');

        $record = DB::table('agent_conversation_messages')
            ->where('conversation_id', $response->conversationId)
            ->where('role', 'assistant')
            ->first();

        $steps = json_decode((string) $record->steps, true);
        $this->assertCount(1, $steps);
        $this->assertSame('They want the temperature.', $steps[0]['reasoning']);
        $this->assertArrayNotHasKey('reasoning', json_decode((string) $record->meta, true));
    }

    public function testItRecordsNoReasoningOnTheTurnStepsWhenTheModelDidNotReason(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Quiet conversation');

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            'How cold is it?',
            [],
            m::mock(TextProvider::class),
            'test-model',
        );

        $response = new StreamedAgentResponse('invocation-id', collect([
            new TextDelta(uniqid(), 'message-1', 'It is 12°C.', time()),
        ]), new Meta);

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

        $this->assertSame('', json_decode((string) $record->steps, true)[0]['reasoning']);
    }

    public function testItRecordsTheSourcesAStreamedTurnCitedIntoTheMessageMeta(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Researched conversation');

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            'What does Hypervel MCP do?',
            [],
            m::mock(TextProvider::class),
            'test-model',
        );

        $response = new StreamedAgentResponse('invocation-id', collect([
            new TextDelta(uniqid(), 'message-1', 'Hypervel MCP ships an MCP server.', time()),
            new CitationEvent(uniqid(), 'message-1', new UrlCitation('https://hypervel.org/docs/mcp', 'Hypervel MCP'), time()),
            new CitationEvent(uniqid(), 'message-1', new UrlCitation('https://hypervel.org/docs/mcp', 'Hypervel MCP'), time()),
        ]), new Meta);

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

        // Every mention is stored, matching what a generated turn records for the same answer...
        $this->assertSame([
            ['url' => 'https://hypervel.org/docs/mcp', 'title' => 'Hypervel MCP', 'start_index' => null, 'end_index' => null],
            ['url' => 'https://hypervel.org/docs/mcp', 'title' => 'Hypervel MCP', 'start_index' => null, 'end_index' => null],
        ], json_decode((string) $record->meta, true)['citations']);
    }

    public function testItStoresNoSourcesWhenAStreamedTurnCitedNothing(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Unresearched conversation');

        $prompt = new AgentPrompt(
            new ToolUsingAgent,
            'How cold is it?',
            [],
            m::mock(TextProvider::class),
            'test-model',
        );

        $response = new StreamedAgentResponse('invocation-id', collect([
            new TextDelta(uniqid(), 'message-1', 'It is 12°C.', time()),
        ]), new Meta);

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $record = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

        $this->assertSame([], json_decode((string) $record->meta, true)['citations']);
    }

    public function testItStoresAUserMessageFromAnAgentClassAndAUserMessage(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Prompt conversation');

        $messageId = $store->storeUserMessage($conversationId, 'user', 1, ToolUsingAgent::class, new UserMessage('Check my order status.'));

        $record = DB::table('agent_conversation_messages')->where('id', $messageId)->first();

        $this->assertSame($conversationId, $record->conversation_id);
        $this->assertSame(ToolUsingAgent::class, $record->agent);
        $this->assertSame('user', $record->role);
        $this->assertSame('Check my order status.', $record->content);
        $this->assertSame('[]', $record->attachments);
    }

    public function testItStoresTheAttachmentsAUserMessageCarries(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Prompt conversation');

        $messageId = $store->storeUserMessage($conversationId, 'user', 1, ToolUsingAgent::class, new UserMessage(
            'What is in this?',
            [new RemoteImage('https://example.com/order.png')],
        ));

        $attachments = json_decode((string) DB::table('agent_conversation_messages')->where('id', $messageId)->value('attachments'), true);

        $this->assertCount(1, $attachments);
        $this->assertSame('https://example.com/order.png', $attachments[0]['url']);
    }

    public function testItTouchesTheConversationWhenAUserMessageIsStored(): void
    {
        $this->freezeTime();

        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Prompt conversation');

        DB::table('agent_conversations')->where('id', $conversationId)->update(['updated_at' => now()->subDay()]);

        $store->storeUserMessage($conversationId, 'user', 1, ToolUsingAgent::class, new UserMessage('Check my order status.'));

        $this->assertSame(now()->toDateTimeString(), DB::table('agent_conversations')->where('id', $conversationId)->value('updated_at'));
    }

    public function testProviderReasoningStateIsKeptOnTheStepReplayBlocksRatherThanCopiedOntoEachToolCall(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Reasoning conversation');
        $prompt = new AgentPrompt(new ToolUsingAgent, 'Look it up', [], m::mock(TextProvider::class), 'test-model');

        $reasoningItem = ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'];

        $response = (new AgentResponse('invocation-1', 'Found it.', new TextUsage, new Meta('openai', 'gpt-5')))
            ->withSteps(collect([new Step(
                'Found it.',
                [new ToolCall('fc_1', 'ReadFile', ['path' => 'a'], 'call_1', 'rs_1', [], 'enc-blob-1')],
                [new ToolResult('fc_1', 'ReadFile', ['path' => 'a'], 'contents', 'call_1')],
                FinishReason::Stop,
                new TextUsage,
                new Meta('openai', 'gpt-5'),
                '',
                [$reasoningItem, ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'ReadFile', 'arguments' => '{"path":"a"}']],
            )]));

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $step = json_decode(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'), true)[0];

        $this->assertSame([
            'id' => 'fc_1',
            'name' => 'ReadFile',
            'arguments' => ['path' => 'a'],
            'result_id' => 'call_1',
            'result' => 'contents',
        ], $step['tool_calls'][0]);
        $this->assertSame([], $step['replay_blocks']);
    }

    public function testAStepThatDroppedAnUnansweredCallReplaysGenericallySoNoRawBlockNamesACallWithoutAResult(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Tool conversation');

        insertAssistantTurn($conversationId, 'message-1', 'Read a', [
            assistantStep(
                [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a']], ['id' => 'call-2', 'name' => 'read_file', 'arguments' => ['path' => 'b']]],
                [['id' => 'call-1', 'name' => 'read_file', 'arguments' => ['path' => 'a'], 'result' => 'contents of a']],
                replayBlocks: [['type' => 'thinking', 'signature' => 'sig-1'], ['type' => 'tool_use', 'id' => 'call-1'], ['type' => 'tool_use', 'id' => 'call-2']],
            ),
        ], meta: ['provider' => 'anthropic']);

        $message = $store->getLatestConversationMessages($conversationId, 10)->first();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame([], $message->replayBlocks);
        $this->assertSame(['call-1'], $message->toolCalls->pluck('id')->all());
    }

    public function testProviderToolCallsAreStoredPerStepAndExposedOnTheStoredMessageAndModel(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', 1, 'Search conversation');
        $prompt = new AgentPrompt(new ToolUsingAgent, 'Search', [], m::mock(TextProvider::class), 'test-model');

        $search = new ProviderToolCall('ws-1', 'web_search_call', ['action' => ['query' => 'hypervel ai']]);
        $execution = new ProviderToolCall('ce-1', 'code_interpreter_call', ['code' => 'print(1)']);

        $response = (new AgentResponse('invocation-1', 'Found it.', new TextUsage, new Meta('openai', 'gpt-5')))
            ->withSteps(collect([
                new Step('', [], [], FinishReason::Stop, new TextUsage, new Meta('openai', 'gpt-5'), '', [], [$search]),
                new Step('Found it.', [], [], FinishReason::Stop, new TextUsage, new Meta('openai', 'gpt-5'), '', [], [$execution]),
            ]));

        $store->storeAssistantMessage($conversationId, 'user', 1, $prompt, $response);

        $steps = json_decode(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'), true);

        $this->assertSame([$search->toArray()], $steps[0]['provider_tool_calls']);
        $this->assertSame([$execution->toArray()], $steps[1]['provider_tool_calls']);
        $this->assertSame([$search->toArray(), $execution->toArray()], $store->paginateConversationMessages($conversationId, 1)->items()[0]->providerToolCalls());
        $this->assertSame([$search->toArray(), $execution->toArray()], ConversationMessage::query()->where('role', 'assistant')->first()->provider_tool_calls);
    }

    public function testACompleteTurnCreatesItsConversationBeforeBothMessages(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = (string) Str::uuid7();
        DB::enableQueryLog();

        $turn = $store->storeTurn($conversationId, 'First conversation', null, null, $this->prompt(), $this->response());

        $this->assertSame($conversationId, $turn->conversationId);
        $this->assertDatabaseHas('agent_conversations', ['id' => $conversationId, 'title' => 'First conversation']);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $turn->userMessageId, 'conversation_id' => $conversationId, 'role' => 'user']);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $turn->assistantMessageId, 'conversation_id' => $conversationId, 'role' => 'assistant']);
        $this->assertSame([], array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'update "agent_conversations"')));
    }

    public function testAnExistingTurnTouchesTheConversationOnlyOnce(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation(null, null, 'Existing');
        DB::enableQueryLog();
        $transactions = 0;
        $this->app->make(Dispatcher::class)->listen(TransactionBeginning::class, static function () use (&$transactions): void {
            ++$transactions;
        });

        $store->storeTurn($conversationId, null, null, null, $this->prompt(), $this->response());

        $touches = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'update "agent_conversations"'));
        $this->assertCount(1, $touches);
        $this->assertSame(1, $transactions);
    }

    public function testFailedAssistantInsertionRollsBackTheWholeNewTurn(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = (string) Str::uuid7();
        $failure = new RuntimeException('Assistant storage failed');
        DB::connection()->beforeExecuting(static function (string $query, array $bindings) use ($failure): void {
            if (str_starts_with($query, 'insert into "agent_conversation_messages"') && in_array('assistant', $bindings, true)) {
                throw $failure;
            }
        });

        try {
            $store->storeTurn($conversationId, 'New conversation', null, null, $this->prompt(), $this->response());
            $this->fail('Expected assistant persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertDatabaseMissing('agent_conversations', ['id' => $conversationId]);
        $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => $conversationId]);

        $store->storeConversation(null, null, 'Retry', $conversationId);
        DB::table('agent_conversations')->where('id', $conversationId)->update(['updated_at' => '2020-01-01 00:00:00']);
        $store->storeUserMessage($conversationId, null, null, AnonymousAgent::class, new UserMessage('Retry'));

        $this->assertNotSame('2020-01-01 00:00:00', DB::table('agent_conversations')->where('id', $conversationId)->value('updated_at'));
    }

    public function testAtomicTurnsHonorPublicOverridesAndLockTheParentBeforeAnInsertFirstOverride(): void
    {
        $alternateId = (string) Str::uuid7();
        $store = new class($alternateId) extends DatabaseConversationStore {
            public function __construct(private string $alternateId)
            {
                parent::__construct();
            }

            /**
             * Create the conversation under the application's own identifier.
             */
            public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
            {
                return parent::storeConversation($participantType, $participantId, 'Custom title', $this->alternateId);
            }

            /**
             * Preserve an insert-first override written against the upstream store.
             */
            public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
            {
                $messageId = (string) Str::uuid7();
                $now = now();
                $this->table($this->messagesTable())->insert($this->messageAttributes($messageId, $conversationId, $participantType, $participantId, $now, [
                    'agent' => $agent,
                    'role' => 'user',
                    'content' => 'Custom user message',
                    'attachments' => '[]',
                    'steps' => '[]',
                    'usage' => '[]',
                    'meta' => '[]',
                    'status' => 'completed',
                ]));
                $this->touchConversation($conversationId, $now);

                return $messageId;
            }

            /**
             * Customize the assistant message before using the default storage.
             */
            public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
            {
                $response->text = 'Custom assistant message';

                return parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
            }
        };
        DB::enableQueryLog();

        $turn = $store->storeTurn((string) Str::uuid7(), 'Ignored title', null, null, $this->prompt(), $this->response());

        $this->assertSame($alternateId, $turn->conversationId);
        $this->assertDatabaseHas('agent_conversations', ['id' => $alternateId, 'title' => 'Custom title']);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $turn->userMessageId, 'conversation_id' => $alternateId, 'content' => 'Custom user message']);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $turn->assistantMessageId, 'conversation_id' => $alternateId, 'content' => 'Custom assistant message']);
        $this->assertSame([], array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'update "agent_conversations"')));

        DB::flushQueryLog();
        $store->storeTurn($alternateId, null, null, null, $this->prompt(), $this->response());
        $queries = array_column(DB::getQueryLog(), 'query');
        $touches = array_keys(array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'update "agent_conversations"')));
        $inserts = array_keys(array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'insert into "agent_conversation_messages"')));

        $this->assertCount(1, $touches);
        $this->assertCount(2, $inserts);
        $this->assertLessThan($inserts[0], $touches[0]);
    }

    public function testAnEmptyResumeDoesNotTouchTheConversation(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation(null, null, 'Existing');
        DB::table('agent_conversations')->where('id', $conversationId)->update(['updated_at' => '2020-01-01 00:00:00']);
        DB::enableQueryLog();

        $turn = $store->storeTurn($conversationId, null, null, null, $this->prompt(Decisions::from(['call-1' => false])), new AgentResponse('invocation', '', new TextUsage, new Meta));

        $this->assertNull($turn->userMessageId);
        $this->assertNull($turn->assistantMessageId);
        $this->assertSame('2020-01-01 00:00:00', DB::table('agent_conversations')->where('id', $conversationId)->value('updated_at'));
        $this->assertSame([], array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'update "agent_conversations"')));
    }

    public function testAStandaloneAssistantWriteOwnsItsTransaction(): void
    {
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation(null, null, 'Existing');
        $transactions = 0;
        $this->app->make(Dispatcher::class)->listen(TransactionBeginning::class, static function () use (&$transactions): void {
            ++$transactions;
        });

        $store->storeAssistantMessage($conversationId, null, null, $this->prompt(), $this->response());

        $this->assertSame(1, $transactions);
        $this->assertDatabaseCount('agent_conversation_messages', 1);
    }

    public function testAResumeRollsBackWorkAnOverridePerformsBeforeCallingItsParent(): void
    {
        $store = new class extends DatabaseConversationStore {
            public ?RuntimeException $failure = null;

            /**
             * Include the application's extra write in the turn transaction.
             */
            public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): ?string
            {
                if ($this->failure !== null) {
                    DB::table('agent_conversations')->where('id', $conversationId)->update(['title' => 'Changed by override']);
                    throw $this->failure;
                }

                return parent::storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response, $exception);
            }
        };
        [$conversationId] = $this->pause($store);
        $prompt = $this->claimedPrompt($store, $conversationId);
        $store->failure = new RuntimeException('Override failed');

        try {
            $store->storeTurn($conversationId, null, null, null, $prompt, $this->response());
            $this->fail('Expected the override to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($store->failure, $exception);
        }

        $this->assertDatabaseHas('agent_conversations', ['id' => $conversationId, 'title' => 'Approval']);
    }

    public function testAnotherTurnInsideAnOverrideRestoresTheOuterTurnsState(): void
    {
        $store = new class extends DatabaseConversationStore {
            public ?Closure $afterUserMessage = null;

            /**
             * Allow the application to record another conversation during persistence.
             */
            public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
            {
                $id = parent::storeUserMessage($conversationId, $participantType, $participantId, $agent, $message);
                ($this->afterUserMessage)();

                return $id;
            }
        };
        $otherStore = new DatabaseConversationStore;
        $outerId = $store->storeConversation(null, null, 'Outer');
        $innerId = $otherStore->storeConversation(null, null, 'Inner');
        $store->afterUserMessage = fn () => $otherStore->storeTurn($innerId, null, null, null, $this->prompt(), $this->response());
        DB::enableQueryLog();

        $store->storeTurn($outerId, null, null, null, $this->prompt(), $this->response());

        $touches = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'update "agent_conversations"')));
        $this->assertCount(2, $touches);
        $this->assertSame($outerId, $touches[0]['bindings'][1]);
        $this->assertSame($innerId, $touches[1]['bindings'][1]);
        $this->assertDatabaseCount('agent_conversation_messages', 4);
    }

    public function testOnlyTheExactPendingCallSetCanBeClaimedOnce(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId, $messageId] = $this->pause($store);

        $this->assertNull($store->claimPendingApprovals($conversationId, ['wrong-call']));
        $this->travel(1)->minute();
        $claim = $store->claimPendingApprovals($conversationId, ['call-1']);

        $this->assertSame($messageId, $claim->messageId);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $messageId, 'updated_at' => now()->toDateTimeString()]);
        $this->assertNull($store->claimPendingApprovals($conversationId, ['call-1']));
        $this->assertSame([], $store->pendingApprovalsFor($conversationId));
    }

    public function testClaimedHistoryDoesNotClearOrReexecuteTheLiveClaim(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId, $messageId] = $this->pause($store);
        $claim = $store->claimPendingApprovals($conversationId, ['call-1']);
        $before = DB::table('agent_conversation_messages')->where('id', $messageId)->first();

        $messages = $store->getLatestConversationMessages($conversationId, 10);
        $result = $messages->first(static fn (object $message): bool => $message instanceof ToolResultMessage)->toolResults->sole();

        $this->assertStringContainsString('in progress or its outcome is unknown', $result->text());
        $this->assertSame('result-1', $result->resultId);
        $this->assertEquals($before, DB::table('agent_conversation_messages')->where('id', $messageId)->first());

        $store->storeTurn($conversationId, null, null, null, $this->prompt(), $this->response());
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $messageId, 'approval_claim' => $claim->token, 'has_replay_blocks' => true]);

        $this->travel(1)->minute();
        $store->recordApprovalResult($claim, new ToolResult('call-1', 'send_email', ['to' => 'updated@example.com'], 'Sent', 'result-1'));
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $messageId, 'updated_at' => now()->toDateTimeString()]);
        $steps = json_decode(DB::table('agent_conversation_messages')->where('id', $messageId)->value('steps'), true);
        $this->assertSame('Sent', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame(['to' => 'updated@example.com'], $steps[0]['tool_calls'][0]['arguments']);
        $this->assertSame('result-1', $steps[0]['tool_calls'][0]['result_id']);
    }

    public function testSettlementFoldsTheClaimedMessageAndClearsItsOwnership(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId, $messageId] = $this->pause($store);
        $prompt = $this->claimedPrompt($store, $conversationId);
        $store->recordApprovalResult($prompt->approvalClaim(), new ToolResult('call-1', 'send_email', [], 'Sent'));
        DB::enableQueryLog();
        $transactions = 0;
        $this->app->make(Dispatcher::class)->listen(TransactionBeginning::class, static function () use (&$transactions): void {
            ++$transactions;
        });

        $turn = $store->storeTurn($conversationId, null, null, null, $prompt, $this->response());

        $touches = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'update "agent_conversations"'));
        $this->assertCount(1, $touches);
        $this->assertSame(1, $transactions);
        $this->assertNull($turn->userMessageId);
        $this->assertSame($messageId, $turn->assistantMessageId);
        $this->assertDatabaseCount('agent_conversation_messages', 1);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $messageId, 'status' => 'completed', 'approval_claim' => null, 'has_replay_blocks' => false]);
        $steps = json_decode(DB::table('agent_conversation_messages')->where('id', $messageId)->value('steps'), true);
        $this->assertSame('Sent', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame('result-1', $steps[0]['tool_calls'][0]['result_id']);
    }

    public function testAResumeOverrideReceivesTheFullPausedRow(): void
    {
        $store = new class extends DatabaseConversationStore {
            /**
             * Include the prior response text when customizing the resumed message.
             */
            protected function resumePausedRow(string $conversationId, object $paused, AgentPrompt $prompt, AgentResponse $response, ?Throwable $exception = null): string
            {
                $response->text = $paused->content . ' ' . $response->text;

                return parent::resumePausedRow($conversationId, $paused, $prompt, $response, $exception);
            }
        };
        [$conversationId, $messageId] = $this->pause($store);
        $prompt = $this->claimedPrompt($store, $conversationId);

        $store->storeTurn($conversationId, null, null, null, $prompt, $this->response());

        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $messageId, 'content' => 'Hello back Hello back']);
    }

    public function testAnotherClaimCannotRecordResults(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId, $messageId] = $this->pause($store);
        $store->claimPendingApprovals($conversationId, ['call-1']);

        $this->expectException(ApprovalMismatchException::class);
        $store->recordApprovalResult(new ApprovalClaim($messageId, (string) Str::uuid7()), new ToolResult('call-1', 'send_email', [], 'Sent'));
    }

    public function testAnUnclaimedRunCannotModifyThePausedTurn(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId, $messageId] = $this->pause($store);
        $prompt = $this->prompt(Decisions::from(['call-1' => true]));
        $prompt->setRunContext(new RunContext('invocation', $prompt->agent, $prompt->provider(), 'model', $this->app->make(Dispatcher::class), approvalStore: $store, conversationId: $conversationId));
        $before = DB::table('agent_conversation_messages')->where('id', $messageId)->first();

        try {
            $store->storeTurn($conversationId, null, null, null, $prompt, $this->response(), new RuntimeException('Invalid approval'));
            $this->fail('Expected a run without ownership to be rejected.');
        } catch (ApprovalMismatchException) {
            $this->assertEquals($before, DB::table('agent_conversation_messages')->where('id', $messageId)->first());
        }

        $this->assertNotNull($store->claimPendingApprovals($conversationId, ['call-1']));
    }

    public function testLegacyResultRecordingCannotOverwriteAClaimedTurn(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId, $messageId] = $this->pause($store);
        $store->claimPendingApprovals($conversationId, ['call-1']);
        $before = DB::table('agent_conversation_messages')->where('id', $messageId)->first();

        try {
            $store->storeApprovalResults($conversationId, [new ToolResult('call-1', 'send_email', [], 'Not owned')]);
            $this->fail('Expected the legacy result writer to reject a claimed turn.');
        } catch (ApprovalMismatchException) {
            $this->assertEquals($before, DB::table('agent_conversation_messages')->where('id', $messageId)->first());
        }
    }

    public function testReplayCleanupDoesNotRewriteAlreadyClearedPauses(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId, $messageId] = $this->pause($store);
        $store->storeTurn($conversationId, null, null, null, $this->prompt(), $this->response());
        $steps = json_decode(DB::table('agent_conversation_messages')->where('id', $messageId)->value('steps'), true);

        $this->assertSame([], $steps[0]['replay_blocks']);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $messageId, 'has_replay_blocks' => false]);
        DB::enableQueryLog();

        $store->storeTurn($conversationId, null, null, null, $this->prompt(), $this->response());

        $rewrites = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'update "agent_conversation_messages"'));
        $this->assertSame([], $rewrites);
    }

    public function testFailedClaimReportsUnknownOutcomesWithoutMakingCallsResumable(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId, $messageId] = $this->pause($store);
        $prompt = $this->claimedPrompt($store, $conversationId);

        $store->storeTurn($conversationId, null, null, null, $prompt, $this->response(), new RuntimeException('Run interrupted'));

        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $messageId, 'status' => 'failed', 'approval_claim' => null]);
        $this->assertNull($store->claimPendingApprovals($conversationId, ['call-1']));
        $result = $store->getLatestConversationMessages($conversationId, 10)
            ->first(static fn (object $message): bool => $message instanceof ToolResultMessage)->toolResults->sole();
        $this->assertStringContainsString('may or may not have run', $result->text());
    }

    public function testDeletingAClaimedConversationRejectsItsNextResult(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId] = $this->pause($store);
        $claim = $store->claimPendingApprovals($conversationId, ['call-1']);
        Conversation::findOrFail($conversationId)->delete();

        $this->expectException(ApprovalMismatchException::class);
        $store->recordApprovalResult($claim, new ToolResult('call-1', 'send_email', [], 'Sent'));
    }

    public function testDeletingAClaimedConversationDoesNotInsertAReplacementOnSettlement(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId] = $this->pause($store);
        $prompt = $this->claimedPrompt($store, $conversationId);
        Conversation::findOrFail($conversationId)->delete();

        try {
            $store->storeTurn($conversationId, null, null, null, $prompt, $this->response());
            $this->fail('Expected settlement of the deleted claim to fail.');
        } catch (ApprovalMismatchException) {
            $this->assertDatabaseCount('agent_conversation_messages', 0);
        }
    }

    public function testAClaimThatPausesAgainKeepsRecordedResultsAndClaimsOnlyTheNewCalls(): void
    {
        $store = new DatabaseConversationStore;
        [$conversationId, $messageId] = $this->pause($store);
        $prompt = $this->claimedPrompt($store, $conversationId);
        $store->recordApprovalResult($prompt->approvalClaim(), new ToolResult('call-1', 'send_email', [], 'Sent'));
        $response = $this->response()->withSteps(collect([
            new Step('', [new ToolCall('call-2', 'delete_file', [])], [], FinishReason::ToolCalls, new TextUsage, new Meta('test', 'model'), '', [['type' => 'tool_use', 'id' => 'call-2']]),
        ]))->withPendingApprovals(collect([new PendingApproval('call-2', 'delete_file', [], 'Deletes a file')]));

        $turn = $store->storeTurn($conversationId, null, null, null, $prompt, $response);

        $this->assertSame($messageId, $turn->assistantMessageId);
        $this->assertSame(['call-2'], array_map(static fn (PendingApproval $approval): string => $approval->id, $store->pendingApprovalsFor($conversationId)));
        $this->assertNull($store->claimPendingApprovals($conversationId, ['call-1']));
        $next = $store->claimPendingApprovals($conversationId, ['call-2']);
        $this->assertSame($messageId, $next->messageId);
        $this->assertNotSame($prompt->approvalClaim()->token, $next->token);
        $steps = json_decode(DB::table('agent_conversation_messages')->where('id', $messageId)->value('steps'), true);
        $this->assertSame('Sent', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame('result-1', $steps[0]['tool_calls'][0]['result_id']);
        $this->assertSame([['type' => 'tool_use', 'id' => 'call-2']], $steps[1]['replay_blocks']);
    }

    #[DataProvider('writeOperations')]
    public function testWritePolicyRejectsMutationsWithoutBlockingReads(string $operation): void
    {
        $store = new class extends DatabaseConversationStore {
            public ?RuntimeException $failure = null;

            /**
             * Reject writes while the account is read-only.
             */
            protected function ensureWriteAllowed(): void
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }
        };
        [$conversationId, $messageId] = $this->pause($store);
        $prompt = $this->claimedPrompt($store, $conversationId);
        $before = DB::table('agent_conversation_messages')->where('id', $messageId)->first();
        $store->failure = new RuntimeException('Account is read-only');

        $this->assertCount(2, $store->getLatestConversationMessages($conversationId, 10));
        try {
            match ($operation) {
                'conversation' => $store->storeConversation(null, null, 'Blocked'),
                'user' => $store->storeUserMessage($conversationId, null, null, AnonymousAgent::class, new UserMessage('Blocked')),
                'assistant' => $store->storeAssistantMessage($conversationId, null, null, $prompt, $this->response()),
                'turn' => $store->storeTurn($conversationId, null, null, null, $prompt, $this->response()),
                'claim' => $store->claimPendingApprovals($conversationId, ['call-1']),
                'result' => $store->recordApprovalResult($prompt->approvalClaim(), new ToolResult('call-1', 'send_email', [], 'Sent')),
                'legacy_result' => $store->storeApprovalResults($conversationId, [new ToolResult('call-1', 'send_email', [], 'Sent')]),
            };
            $this->fail('Expected the write policy to reject the operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame($store->failure, $exception);
        }

        $this->assertDatabaseCount('agent_conversations', 1);
        $this->assertDatabaseCount('agent_conversation_messages', 1);
        $this->assertEquals($before, DB::table('agent_conversation_messages')->where('id', $messageId)->first());
    }

    /**
     * Exercise each public raw-store mutation boundary.
     */
    public static function writeOperations(): array
    {
        return [['conversation'], ['user'], ['assistant'], ['turn'], ['claim'], ['result'], ['legacy_result']];
    }

    /**
     * Create a persisted pause with provider replay data.
     *
     * @return array{string, string}
     */
    private function pause(DatabaseConversationStore $store): array
    {
        $conversationId = $store->storeConversation(null, null, 'Approval');
        $response = $this->response()->withSteps(collect([
            new Step('', [new ToolCall('call-1', 'send_email', [], 'result-1')], [], FinishReason::ToolCalls, new TextUsage, new Meta, '', [['type' => 'tool_use', 'id' => 'call-1']]),
        ]))->withPendingApprovals(collect([new PendingApproval('call-1', 'send_email', [], 'Sends an email')]));

        return [$conversationId, $store->storeAssistantMessage($conversationId, null, null, $this->prompt(), $response)];
    }

    /**
     * Attach a real store-backed claim through the generation run context.
     */
    private function claimedPrompt(DatabaseConversationStore $store, string $conversationId): AgentPrompt
    {
        $prompt = $this->prompt(Decisions::from(['call-1' => true]));
        $context = new RunContext('invocation', $prompt->agent, $prompt->provider(), 'model', $this->app->make(Dispatcher::class), approvalStore: $store, conversationId: $conversationId);
        $context->claimPendingApprovals(['call-1']);
        $prompt->setRunContext($context);

        return $prompt;
    }

    /**
     * Create a prompt without contacting a provider.
     */
    private function prompt(?Decisions $decisions = null): AgentPrompt
    {
        return new AgentPrompt(new AnonymousAgent('', [], []), 'Hello', [], m::mock(TextProvider::class), 'model', approvalDecisions: $decisions);
    }

    /**
     * Create the generated response to persist.
     */
    private function response(): AgentResponse
    {
        return new AgentResponse('invocation', 'Hello back', new TextUsage, new Meta('test', 'model'));
    }
}

/**
 * Build a Gemini interaction response for the remembered-agent tests.
 */
function storeTestGeminiInteraction(array $steps): array
{
    return [
        'id' => 'int_store',
        'model' => 'gemini-3.5-flash',
        'status' => 'completed',
        'steps' => $steps,
        'usage' => ['total_input_tokens' => 10, 'total_output_tokens' => 5, 'total_tokens' => 15],
    ];
}

/**
 * Create the storage tables on a custom name or connection.
 */
function createConversationSchema(?string $connection = null): void
{
    $schema = Schema::connection($connection);

    $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
    $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

    $schema->create($conversationsTable, function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('participant_type')->nullable();
        $table->string('participant_id')->nullable();
        $table->string('title');
        $table->timestamps();
    });

    $schema->create($messagesTable, function (Blueprint $table) use ($conversationsTable): void {
        $table->uuid('id')->primary();
        $table->foreignUuid('conversation_id')->constrained($conversationsTable)->cascadeOnDelete();
        $table->string('participant_type')->nullable();
        $table->string('participant_id')->nullable();
        $table->string('agent');
        $table->string('role');
        $table->longText('content');
        $table->longText('attachments');
        $table->longText('steps');
        $table->longText('usage');
        $table->longText('meta');
        $table->string('status');
        $table->uuid('approval_claim')->nullable();
        $table->boolean('has_replay_blocks')->default(false);
        $table->timestamps();
    });
}

/**
 * Build a stored user message row.
 *
 * @return array<string, mixed>
 */
function storedConversationMessageAttributes(string $id, string $conversationId, string $content): array
{
    return [
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => $content,
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'status' => MessageStatus::Completed,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/**
 * Pair a step's calls with their recorded outcomes.
 *
 * @param list<array<string, mixed>> $toolCalls
 * @param list<array<string, mixed>> $toolResults
 * @param list<array<string, mixed>> $replayBlocks
 * @return array<string, mixed>
 */
function assistantStep(array $toolCalls = [], array $toolResults = [], array $replayBlocks = [], string $content = ''): array
{
    $results = collect($toolResults)->keyBy('id');

    return [
        'content' => $content,
        'tool_calls' => array_map(fn (array $call): array => [
            ...$call,
            ...Arr::only($results[$call['id']] ?? [], ['result', 'denied', 'failed']),
        ], $toolCalls),
        'replay_blocks' => $replayBlocks,
    ];
}

/**
 * Insert an assistant turn with optional pending approvals.
 *
 * @param list<array<string, mixed>> $steps
 * @param null|array<string, null|string> $pending Reasons keyed by the tool call IDs still awaiting a decision, or null when the turn never paused
 * @param array<string, mixed> $meta
 */
function insertAssistantTurn(string $conversationId, string $id, string $content, array $steps, ?array $pending = null, array $meta = []): void
{
    if ($steps !== [] && $steps[array_key_last($steps)]['content'] === '') {
        $steps[array_key_last($steps)]['content'] = $content;
    }

    $steps = array_map(fn (array $step): array => [...$step, 'tool_calls' => array_map(
        fn (array $toolCall) => array_key_exists($toolCall['id'], $pending ?? []) ? [...$toolCall, 'approval_reason' => $pending[$toolCall['id']]] : $toolCall,
        $step['tool_calls'],
    )], $steps);

    DB::table('agent_conversation_messages')->insert([
        ...storedConversationMessageAttributes($id, $conversationId, $content),
        'role' => 'assistant',
        'steps' => json_encode($steps),
        'meta' => json_encode($meta),
        'status' => $pending === null ? MessageStatus::Completed : MessageStatus::Paused,
        'has_replay_blocks' => collect($steps)->contains(fn (array $step): bool => $step['replay_blocks'] !== []),
    ]);
}

/**
 * Insert messages with predictable cursor ordering.
 *
 * @param list<string> $ids
 */
function insertStoredConversationMessages(string $conversationId, array $ids): void
{
    DB::table('agent_conversation_messages')->insert(
        collect($ids)->map(fn (string $id): array => storedConversationMessageAttributes($id, $conversationId, "Content for {$id}"))->all()
    );
}

/**
 * Insert a paused turn with the given calls and approval reasons.
 *
 * @param list<array<string, mixed>> $toolCalls
 * @param array<string, null|string> $pending
 */
function insertPausedConversationTurn(string $conversationId, string $id, array $toolCalls, array $pending): void
{
    insertAssistantTurn($conversationId, $id, 'Waiting on you.', [assistantStep($toolCalls)], $pending);
}
