<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Responses\StructuredAgentResponse;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingMultiStepApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingStructuredApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\ApprovableNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ToolApprovalResumeTest extends TestCase
{
    use RefreshDatabase;
    use AnthropicHelpers;

    public function testARememberedAgentPausesForApprovalPersistsTheToolUseAndResumesFromHistoryWhenApproved(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $this->assertTrue($paused->hasPendingApprovals());
        $this->assertCount(1, $paused->pendingApprovals);
        $this->assertSame('toolu_1', $paused->pendingApprovals[0]->id);
        $this->assertNotNull($paused->conversationId);

        $assistantRow = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $steps = json_decode($assistantRow->steps, true);
        $this->assertCount(1, $steps);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertSame('toolu_1', $steps[0]['tool_calls'][0]['id']);
        $this->assertArrayNotHasKey('result', $steps[0]['tool_calls'][0]);

        $resumed = (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $this->assertFalse($resumed->hasPendingApprovals());
        $this->assertSame('The number is 72019.', $resumed->text);
        $this->assertCount(1, $resumed->toolResults);
        $this->assertSame('72019', $resumed->toolResults[0]->result);
    }

    public function testAnOwnerlessRememberedAgentPausesForApprovalAndResumesWithoutAParticipant(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $paused = (new RememberingApprovableAgent)->prompt('Generate a number', provider: 'anthropic');

        $this->assertTrue($paused->hasPendingApprovals());
        $this->assertCount(1, $paused->pendingApprovals);
        $this->assertSame('toolu_1', $paused->pendingApprovals[0]->id);
        $this->assertNotNull($paused->conversationId);
        $this->assertNull($paused->conversationUser);

        $assistantRow = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $this->assertNull($assistantRow->participant_type);
        $this->assertNull($assistantRow->participant_id);
        $steps = json_decode($assistantRow->steps, true);
        $this->assertCount(1, $steps);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertSame('toolu_1', $steps[0]['tool_calls'][0]['id']);
        $this->assertArrayNotHasKey('result', $steps[0]['tool_calls'][0]);

        $resumed = (new RememberingApprovableAgent)
            ->continue($paused->conversationId)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $this->assertFalse($resumed->hasPendingApprovals());
        $this->assertSame('The number is 72019.', $resumed->text);
        $this->assertCount(1, $resumed->toolResults);
        $this->assertSame('72019', $resumed->toolResults[0]->result);
    }

    #[DataProvider('resumers')]
    public function testAResumeSettlesThePausedTurnAndRunsTheToolOnceWhoeverResumesIt(?object $resumer): void
    {
        config(['ai.conversations.generate_title' => false]);

        ApprovableNumberGenerator::$invocations = 0;

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $paused = (new RememberingApprovableAgent)->forUser((object) ['id' => 1])->prompt('Generate a number', provider: 'anthropic');

        $resumed = (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $resumer)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $this->assertSame('The number is 72019.', $resumed->text);
        $this->assertSame(1, ApprovableNumberGenerator::$invocations);
        $this->assertSame([], (new DatabaseConversationStore)->pendingApprovalsFor($paused->conversationId));
    }

    /**
     * Provide participants resuming an existing conversation.
     */
    public static function resumers(): array
    {
        return [
            'another participant' => [(object) ['id' => 2]],
            'no participant' => [null],
        ];
    }

    public function testAResumedApprovalReplaysThePausedTurnReplayBlocks(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        [
                            'type' => 'thinking',
                            'thinking' => 'Deciding whether to call the tool.',
                            'signature' => 'signature-1',
                        ],
                        [
                            'type' => 'tool_use',
                            'id' => 'toolu_1',
                            'name' => 'ApprovableNumberGenerator',
                            'input' => (object) [],
                        ],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $this->assertTrue($paused->hasPendingApprovals());

        (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $resumeMessages = collect(Http::recorded())->last()[0]->data()['messages'];

        $assistantTurn = collect($resumeMessages)->firstWhere('role', 'assistant');

        $this->assertSame('thinking', $assistantTurn['content'][0]['type']);
        $this->assertSame('signature-1', $assistantTurn['content'][0]['signature']);
        $this->assertSame('toolu_1', collect($assistantTurn['content'])->firstWhere('type', 'tool_use')['id']);
    }

    public function testAResumeAfterAMultiStepPauseReplaysEachStepWithItsOwnSignedThinkingAndAnswersItsToolUse(): void
    {
        config(['ai.conversations.generate_title' => false]);

        $message = fn (array $content, string $stopReason): PromiseInterface => Http::response([
            'id' => 'msg',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $message([
                    ['type' => 'thinking', 'thinking' => 'First the fixed number.', 'signature' => 'signature-1'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                ], 'tool_use'),
                $message([
                    ['type' => 'thinking', 'thinking' => 'Now the gated number.', 'signature' => 'signature-2'],
                    ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []],
                ], 'tool_use'),
                $message([['type' => 'text', 'text' => 'Both numbers are 72019.']], 'end_turn'),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Generate both numbers', provider: 'anthropic');

        $this->assertSame(['toolu_2'], $paused->pendingApprovals->pluck('id')->all());

        $resumed = (new RememberingMultiStepApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

        $resumeMessages = collect(Http::recorded())->last()[0]->data()['messages'];

        $turns = collect($resumeMessages)->map(fn (array $message): array => [
            $message['role'],
            collect($message['content'])->map(fn (array $block): string => $block['tool_use_id'] ?? $block['id'] ?? $block['type'])->all(),
        ])->all();

        $signatures = collect($resumeMessages)
            ->flatMap(fn (array $message): Collection => collect($message['content'])->pluck('signature')->filter())
            ->all();

        $this->assertSame('Both numbers are 72019.', $resumed->text);
        $this->assertSame([
            ['user', ['text']],
            ['assistant', ['thinking', 'toolu_1']],
            ['user', ['toolu_1']],
            ['assistant', ['thinking', 'toolu_2']],
            ['user', ['toolu_2']],
        ], $turns);
        $this->assertSame(['signature-1', 'signature-2'], $signatures);
    }

    public function testAStreamedMultiStepPauseStoresEveryStepSoTheResumeReplaysEachOneWithItsOwnBlocks(): void
    {
        config(['ai.conversations.generate_title' => false]);

        $sse = fn (array $events): PromiseInterface => Http::response(
            implode("\n\n", array_map(fn (array $event): string => 'data: ' . json_encode($event), $events)) . "\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        );

        $step = fn (string $signature, string $toolId, string $toolName): PromiseInterface => $sse([
            ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Deciding.']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => $signature]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => $toolId, 'name' => $toolName, 'input' => (object) []]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $step('signature-1', 'toolu_1', 'FixedNumberGenerator'),
                $step('signature-2', 'toolu_2', 'ApprovableNumberGenerator'),
                $sse([
                    ['type' => 'message_start', 'message' => ['id' => 'msg_2', 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
                    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Both numbers are 72019.']],
                    ['type' => 'content_block_stop', 'index' => 0],
                    ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->stream('Generate both numbers', provider: 'anthropic');

        $paused->each(fn (): bool => true);

        $steps = json_decode(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'), true);
        $this->assertCount(2, $steps);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertCount(1, $steps[1]['tool_calls']);
        $this->assertSame('toolu_1', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame('toolu_2', $steps[1]['tool_calls'][0]['id']);
        $this->assertSame('signature-1', $steps[0]['replay_blocks'][0]['signature']);
        $this->assertSame('signature-2', $steps[1]['replay_blocks'][0]['signature']);

        $resumed = (new RememberingMultiStepApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->stream(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

        $resumed->each(fn (): bool => true);

        $resumeMessages = collect(Http::recorded())->last()[0]->data()['messages'];

        $turns = collect($resumeMessages)->map(fn (array $message): array => [
            $message['role'],
            collect($message['content'])->map(fn (array $block): string => $block['tool_use_id'] ?? $block['id'] ?? $block['type'])->all(),
        ])->all();

        $this->assertSame([
            ['user', ['text']],
            ['assistant', ['thinking', 'toolu_1']],
            ['user', ['toolu_1']],
            ['assistant', ['thinking', 'toolu_2']],
            ['user', ['toolu_2']],
        ], $turns);
    }

    public function testAResumeOnADifferentProviderFallsBackToTheGenericMappingInsteadOfReplayingForeignBlocks(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        [
                            'type' => 'thinking',
                            'thinking' => 'Deciding whether to call the tool.',
                            'signature' => 'signature-1',
                        ],
                        [
                            'type' => 'tool_use',
                            'id' => 'toolu_1',
                            'name' => 'ApprovableNumberGenerator',
                            'input' => (object) [],
                        ],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $this->assertTrue($paused->hasPendingApprovals());

        // Rewrite the paused row as if the pause had happened on a failover provider...
        DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('role', 'assistant')
            ->get()
            ->each(function (object $row): void {
                $meta = json_decode($row->meta, true);
                $meta['provider'] = 'openai';

                DB::table('agent_conversation_messages')->where('id', $row->id)->update(['meta' => json_encode($meta)]);
            });

        (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $resumeMessages = collect(Http::recorded())->last()[0]->data()['messages'];

        $assistantTurn = collect($resumeMessages)->firstWhere('role', 'assistant');

        $this->assertNull(collect($assistantTurn['content'])->firstWhere('type', 'thinking'));
        $this->assertSame('toolu_1', collect($assistantTurn['content'])->firstWhere('type', 'tool_use')['id']);
    }

    public function testAResumeThatPausesAgainCanItselfBeResumed(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_tool_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_2',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_3',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'Both numbers generated.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate two numbers', provider: 'anthropic');

        $pausedAgain = (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $this->assertTrue($pausedAgain->hasPendingApprovals());
        $this->assertSame('toolu_2', $pausedAgain->pendingApprovals[0]->id);

        $resumed = (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

        $this->assertFalse($resumed->hasPendingApprovals());
        $this->assertSame('Both numbers generated.', $resumed->text);
    }

    public function testAPlainPromptAfterAnAbandonedPauseSettlesTheDanglingToolCall(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'Hi there.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_3',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'Hi again.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $this->assertTrue($paused->hasPendingApprovals());

        $reply = (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt('Never mind, just say hi', provider: 'anthropic');

        $this->assertSame('Hi there.', $reply->text);

        $messages = collect(Http::recorded())->last()[0]->data()['messages'];
        $toolResults = collect($messages)
            ->flatMap(fn (array $message): array => is_array($message['content']) ? $message['content'] : [])
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_result');

        $this->assertCount(1, $toolResults);
        $this->assertSame('toolu_1', $toolResults->first()['tool_use_id']);

        (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt('Say hi once more', provider: 'anthropic');

        $messages = collect(collect(Http::recorded())->last()[0]->data()['messages'])->values();

        $toolUseIndex = $messages->search(fn (array $message): bool => collect(is_array($message['content']) ? $message['content'] : [])
            ->contains(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_use' && $block['id'] === 'toolu_1'));

        $answeringBlocks = collect($messages[$toolUseIndex + 1]['content'] ?? [])
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_result');

        $this->assertNotFalse($toolUseIndex);
        $this->assertSame(['toolu_1'], $answeringBlocks->pluck('tool_use_id')->all());
    }

    public function testAStreamedResumeWithMismatchedDecisionsThrowsBeforeTheStreamBeginsAndReleasesTheClaim(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        try {
            (new RememberingApprovableAgent)
                ->continue($paused->conversationId, $user)
                ->stream(Decisions::from(['bogus-id' => true]), provider: 'anthropic');
            $this->fail('Expected mismatched approval decisions to be rejected.');
        } catch (ApprovalMismatchException) {
        }

        $resumed = (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $this->assertSame('The number is 72019.', $resumed->text);
    }

    public function testAValidStreamedResumeChecksAGatedToolCallForApprovalExactlyOnce(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response($this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'The number is 72019.']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 5),
                ]), 200, ['Content-Type' => 'text/event-stream']),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        ApprovableNumberGenerator::$approvalChecks = 0;

        $response = (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->stream(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $response->each(fn (): bool => true);

        $this->assertSame(1, ApprovableNumberGenerator::$approvalChecks);
        $this->assertSame('The number is 72019.', $response->text);
    }

    public function testAResumeDoesNotFailOverToAnotherProviderAndRerunTheApprovedTool(): void
    {
        config(['ai.conversations.generate_title' => false]);

        config([
            'ai.providers.primary' => ['driver' => 'anthropic', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'anthropic', 'key' => 'test-key'],
        ]);

        ApprovableNumberGenerator::$invocations = 0;

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200)
                ->push(status: 429)
                ->push(status: 429),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: ['primary', 'backup']);

        try {
            (new RememberingApprovableAgent)
                ->continue($paused->conversationId, $user)
                ->prompt(Decisions::from(['toolu_1' => true]), provider: ['primary', 'backup']);
            $this->fail('Expected the provider rate limit to propagate.');
        } catch (RateLimitedException) {
        }

        $this->assertSame(1, ApprovableNumberGenerator::$invocations);
    }

    public function testASuccessfulResumeRecordsTheApprovedResultExactlyOnceAcrossHistory(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200)
                ->push([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        (new RememberingApprovableAgent)->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $recorded = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->pluck('steps')
            ->flatMap(fn (string $steps): Collection => collect(json_decode($steps, true))->flatMap(fn (array $step): Collection => collect($step['tool_calls'])->filter(fn (array $toolCall): bool => array_key_exists('result', $toolCall))->pluck('id')))
            ->filter(fn (string $id): bool => $id === 'toolu_1');

        $this->assertCount(1, $recorded);
    }

    public function testARejectedResumeStoresAndRehydratesTheToolResultAsDenied(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $rejected = (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => false]), provider: 'anthropic');

        $this->assertFalse($rejected->hasPendingApprovals());

        $assistantRow = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        $steps = json_decode($assistantRow->steps, true);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertTrue($steps[0]['tool_calls'][0]['denied']);

        $store = new DatabaseConversationStore;
        $messages = $store->getLatestConversationMessages($paused->conversationId, 10);

        $toolResultMessage = $messages->first(fn (object $message): bool => $message instanceof ToolResultMessage);

        $this->assertTrue($toolResultMessage->toolResults->first()->denied);
    }

    public function testARejectedResumeOfAStructuredAgentReturnsAStructuredResponse(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingStructuredApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $rejected = (new RememberingStructuredApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => false]), provider: 'anthropic');

        $this->assertInstanceOf(StructuredAgentResponse::class, $rejected);
        $this->assertSame([], $rejected->structured);
    }

    public function testAWildcardRejectionResumeRecordsTheDenialOnceOnThePausedRow(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['*' => false]), provider: 'anthropic');

        $assistantRows = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('role', 'assistant')
            ->get();

        $this->assertCount(1, $assistantRows);
        $steps = json_decode($assistantRows->first()->steps, true);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertSame('toolu_1', $steps[0]['tool_calls'][0]['id']);
        $this->assertTrue($steps[0]['tool_calls'][0]['denied']);

        $messages = (new DatabaseConversationStore)->getLatestConversationMessages($paused->conversationId, 10);

        $this->assertCount(1, $messages->whereInstanceOf(ToolResultMessage::class));
    }

    public function testAResumeThatFailsAfterTheToolRunsDoesNotReExecuteTheToolOnRetry(): void
    {
        config(['ai.conversations.generate_title' => false]);

        ApprovableNumberGenerator::$invocations = 0;

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200)
                ->push(status: 500),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        try {
            (new RememberingApprovableAgent)
                ->continue($paused->conversationId, $user)
                ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');
            $this->fail('Expected the provider failure to propagate.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response->status());
        }

        $this->assertSame(1, ApprovableNumberGenerator::$invocations);

        try {
            (new RememberingApprovableAgent)
                ->continue($paused->conversationId, $user)
                ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');
            $this->fail('Expected a settled approval to be rejected.');
        } catch (ApprovalMismatchException) {
        }

        $this->assertSame(1, ApprovableNumberGenerator::$invocations);
    }

    public function testAResumeFoldsItsResultAndReplyIntoThePausedRowInsteadOfWritingANewerOne(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ], [
                        'type' => 'tool_use',
                        'id' => 'toolu_2',
                        'name' => 'ApprovableNumberGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];
        $store = new DatabaseConversationStore;

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $this->assertSame(['toolu_1', 'toolu_2'], collect($store->pendingApprovalsFor($paused->conversationId))->pluck('id')->all());

        $pausedRowId = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('role', 'assistant')
            ->latest('id')
            ->value('id');

        (new RememberingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true, 'toolu_2' => true]), provider: 'anthropic');

        $pausedRow = DB::table('agent_conversation_messages')->where('id', $pausedRowId)->first();

        $newerRows = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('id', '>', $pausedRowId)
            ->count();

        $this->assertSame(0, $newerRows);
        $this->assertSame('The number is 72019.', $pausedRow->content);
        $this->assertCount(2, json_decode($pausedRow->steps, true));
        $this->assertSame([], $store->pendingApprovalsFor($paused->conversationId));
    }

    public function testACompletedTwoStepTurnReplaysStepByStepOnTheNextPrompt(): void
    {
        config(['ai.conversations.generate_title' => false]);

        $toolUse = fn (string $id, string $text): PromiseInterface => Http::response([
            'id' => "msg_{$id}",
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => $text],
                ['type' => 'tool_use', 'id' => $id, 'name' => 'FixedNumberGenerator', 'input' => (object) []],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $text = fn (string $text): PromiseInterface => Http::response([
            'id' => 'msg_text',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $toolUse('toolu_1', 'First number'),
                $toolUse('toolu_2', 'Second number'),
                $text('The numbers are 72019 and 72019.'),
                $text('Yes, both.'),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $first = (new RememberingToolUsingAgent)->forUser($user)->prompt('Generate two numbers', provider: 'anthropic');

        (new RememberingToolUsingAgent)->continue($first->conversationId, $user)->prompt('Are you sure?', provider: 'anthropic');

        $history = collect(Http::recorded())->last()[0]->data()['messages'];

        $this->assertCount(7, $history);
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user', 'assistant', 'user'], array_column($history, 'role'));

        foreach ([1 => ['First number', 'toolu_1'], 3 => ['Second number', 'toolu_2']] as $index => [$expectedText, $toolId]) {
            $this->assertCount(2, $history[$index]['content']);
            $this->assertSame('text', $history[$index]['content'][0]['type']);
            $this->assertSame($expectedText, $history[$index]['content'][0]['text']);
            $this->assertSame('tool_use', $history[$index]['content'][1]['type']);
            $this->assertSame($toolId, $history[$index]['content'][1]['id']);
            $this->assertCount(1, $history[$index + 1]['content']);
            $this->assertSame('tool_result', $history[$index + 1]['content'][0]['type']);
            $this->assertSame($toolId, $history[$index + 1]['content'][0]['tool_use_id']);
        }

        foreach ([5 => 'The numbers are 72019 and 72019.', 6 => 'Are you sure?'] as $index => $expectedText) {
            $this->assertNotEmpty($history[$index]['content']);
            foreach ($history[$index]['content'] as $block) {
                $this->assertSame('text', $block['type']);
                $this->assertSame($expectedText, $block['text']);
            }
        }
    }

    public function testATurnThatPausesTwiceReplaysEveryStepOfTheTurnWithItsReplayBlocksOnTheSecondResume(): void
    {
        config(['ai.conversations.generate_title' => false]);

        $gated = fn (string $signature, string $id): PromiseInterface => Http::response([
            'id' => "msg_{$id}",
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Deciding.', 'signature' => $signature],
                ['type' => 'tool_use', 'id' => $id, 'name' => 'ApprovableNumberGenerator', 'input' => (object) []],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $gated('signature-1', 'toolu_1'),
                $gated('signature-2', 'toolu_2'),
                Http::response([
                    'id' => 'msg_text',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'Both numbers are 72019.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $first = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Generate two gated numbers', provider: 'anthropic');

        $second = (new RememberingMultiStepApprovableAgent)
            ->continue($first->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        $this->assertSame(['toolu_1'], $first->pendingApprovals->pluck('id')->all());
        $this->assertSame(['toolu_2'], $second->pendingApprovals->pluck('id')->all());

        (new RememberingMultiStepApprovableAgent)
            ->continue($first->conversationId, $user)
            ->prompt(Decisions::from(['toolu_2' => true]), provider: 'anthropic');

        $history = collect(Http::recorded())->last()[0]->data()['messages'];

        $this->assertCount(5, $history);
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_column($history, 'role'));

        foreach ([1 => ['signature-1', 'toolu_1'], 3 => ['signature-2', 'toolu_2']] as $index => [$signature, $toolId]) {
            $this->assertSame('thinking', $history[$index]['content'][0]['type']);
            $this->assertSame($signature, $history[$index]['content'][0]['signature']);
            $this->assertNotEmpty($history[$index + 1]['content']);
            foreach ($history[$index + 1]['content'] as $block) {
                $this->assertSame('tool_result', $block['type']);
                $this->assertSame($toolId, $block['tool_use_id']);
            }
        }
    }

    public function testAPlainCallSharingAStepWithAGatedCallRunsAtThePauseAndBothResultsLandOnThePausedRow(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        ['type' => 'tool_use', 'id' => 'toolu_plain', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                        ['type' => 'tool_use', 'id' => 'toolu_gated', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'Both done.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Go', provider: 'anthropic');

        $store = new DatabaseConversationStore;

        $pausedCalls = collect(json_decode(DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'), true)[0]['tool_calls'])->keyBy('id');

        $this->assertArrayHasKey('result', $pausedCalls['toolu_plain']);
        $this->assertArrayNotHasKey('approval_reason', $pausedCalls['toolu_plain']);
        $this->assertArrayHasKey('approval_reason', $pausedCalls['toolu_gated']);
        $this->assertArrayNotHasKey('result', $pausedCalls['toolu_gated']);
        $pending = $store->pendingApprovalsFor($paused->conversationId);
        $this->assertCount(1, $pending);
        $this->assertSame('toolu_gated', $pending[0]->id);

        (new RememberingMultiStepApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_gated' => true]), provider: 'anthropic');

        $sentResultIds = collect(collect(Http::recorded())->last()[0]->data()['messages'])
            ->flatMap(fn (array $message): array => is_array($message['content']) ? $message['content'] : [])
            ->where('type', 'tool_result')
            ->pluck('tool_use_id')
            ->all();

        $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();
        $calls = collect(json_decode($row->steps, true)[0]['tool_calls'])->keyBy('id');

        $this->assertSame(['toolu_plain', 'toolu_gated'], $sentResultIds);
        $this->assertArrayHasKey('result', $calls['toolu_gated']);
        $this->assertSame([], $store->pendingApprovalsFor($paused->conversationId));
    }

    public function testAResumeThatDiesAfterAStepFailsTheRowItPausedOnInsteadOfWritingANewerOne(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ])
                ->push([
                    'id' => 'msg_tool_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'FixedNumberGenerator', 'input' => (object) []]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ])
                ->pushStatus(500)
                ->pushStatus(500)
                ->pushStatus(500),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $pausedRowId = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('role', 'assistant')
            ->latest('id')
            ->value('id');

        try {
            (new RememberingMultiStepApprovableAgent)
                ->continue($paused->conversationId, $user)
                ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');
            $this->fail('Expected the provider failure to propagate.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response->status());
        }

        $rows = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('role', 'assistant')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($pausedRowId, $rows->first()->id);
        $this->assertSame(MessageStatus::Failed->value, $rows->first()->status);
        $steps = json_decode($rows->first()->steps, true);
        $this->assertCount(2, $steps);
        $this->assertSame('toolu_1', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame('72019', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame('toolu_2', $steps[1]['tool_calls'][0]['id']);
        $this->assertSame('72019', $steps[1]['tool_calls'][0]['result']);
    }

    public function testAResumeThatDiesBeforeItsFirstStepStillFailsTheRowItsApprovalWasWrittenTo(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ])
                ->pushStatus(500)
                ->pushStatus(500)
                ->pushStatus(500)
                ->pushStatus(500),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingMultiStepApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        try {
            (new RememberingMultiStepApprovableAgent)
                ->continue($paused->conversationId, $user)
                ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');
            $this->fail('Expected the provider failure to propagate.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response->status());
        }

        $rows = DB::table('agent_conversation_messages')
            ->where('conversation_id', $paused->conversationId)
            ->where('role', 'assistant')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(MessageStatus::Failed->value, $rows->first()->status);
        $this->assertNotEmpty(json_decode($rows->first()->meta, true)['error']);
        $steps = json_decode($rows->first()->steps, true);
        $this->assertCount(1, $steps);
        $this->assertSame('toolu_1', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame('72019', $steps[0]['tool_calls'][0]['result']);
    }
}
