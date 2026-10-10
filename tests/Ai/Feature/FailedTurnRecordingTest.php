<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Exception;
use Hypervel\Ai\Enums\MessageStatus;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingFailingToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;

#[WithConfig('ai.conversations.generate_title', false)]
#[WithConfig('ai.providers.anthropic', ['driver' => 'anthropic', 'key' => 'test-key'])]
class FailedTurnRecordingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an Anthropic tool response.
     */
    private function anthropicToolTurn(string $id, string $name = 'FixedNumberGenerator'): array
    {
        return [
            'id' => 'msg_' . $id,
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) []]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /**
     * Get the recorded assistant row.
     */
    private function failedAssistantRow(): ?object
    {
        return DB::table('agent_conversation_messages')->where('role', 'assistant')->first();
    }

    /**
     * Create an Anthropic streamed tool response.
     */
    private function anthropicToolStream(string $id, string $name = 'FixedNumberGenerator'): string
    {
        return implode("\n\n", array_map(fn (array $event): string => 'data: ' . json_encode($event), [
            ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
        ])) . "\n\n";
    }

    public function testATurnThatDiesAfterAToolRanKeepsTheStepAndItsResult(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->anthropicToolTurn('toolu_1'))
            ->pushStatus(500)
            ->pushStatus(500)
            ->pushStatus(500),
        ]);

        $this->assertThrows(fn () => (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt('Go', provider: 'anthropic'), RequestException::class);

        $row = $this->failedAssistantRow();

        $this->assertSame(MessageStatus::Failed->value, $row->status);
        $this->assertNotEmpty(json_decode($row->meta, true)['error']);
        $steps = json_decode($row->steps, true);
        $this->assertCount(1, $steps);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertSame('toolu_1', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame('72019', $steps[0]['tool_calls'][0]['result']);
        $this->assertSame(1, DB::table('agent_conversation_messages')->where('role', 'user')->count());
    }

    public function testAFailedTurnReplaysItsAnsweredCallAndFlagsTheOneItNeverAnswered(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->anthropicToolTurn('toolu_1'))
            ->pushStatus(500)
            ->pushStatus(500)
            ->pushStatus(500),
        ]);

        $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

        $this->assertThrows(fn () => $agent->prompt('Go', provider: 'anthropic'), RequestException::class);

        $conversationId = DB::table('agent_conversations')->value('id');

        $messages = (new DatabaseConversationStore)->getLatestConversationMessages($conversationId, 10);

        $this->assertInstanceOf(ToolResultMessage::class, $messages->last());
        $this->assertCount(1, $messages->last()->toolResults);
        $this->assertSame('toolu_1', $messages->last()->toolResults[0]->id);
        $this->assertSame('72019', $messages->last()->toolResults[0]->result);
        $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
        $this->assertCount(1, $messages[1]->toolCalls);
        $this->assertSame('toolu_1', $messages[1]->toolCalls[0]->id);
    }

    public function testATurnThatDiesBeforeAnyStepRecordsNothing(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->assertThrows(fn () => (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Go', provider: 'anthropic'), RequestException::class);

        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
        $this->assertSame(0, DB::table('agent_conversations')->count());
    }

    public function testAnAgentWithNothingToRememberRecordsNothingWhenItDies(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->anthropicToolTurn('toolu_1'))
            ->pushStatus(500)
            ->pushStatus(500)
            ->pushStatus(500),
        ]);

        $this->assertThrows(fn () => (new RememberingToolUsingAgent)->prompt('Go', provider: 'anthropic'), RequestException::class);

        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
    }

    public function testAnAttemptThatFailsOverToAnotherProviderLeavesNoFailedTurnBehind(): void
    {
        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::fakeSequence()
            ->pushStatus(429)
            ->push([
                'id' => 'chat-1',
                'model' => 'llama',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Done.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]);

        (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Go', provider: ['primary', 'backup']);

        $this->assertSame(['completed'], DB::table('agent_conversation_messages')->where('role', 'assistant')->pluck('status')->all());
    }

    public function testAStreamThatDiesMidFlightKeepsTheStepsItCompleted(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->anthropicToolStream('toolu_1'), 200, ['Content-Type' => 'text/event-stream'])
            ->pushStatus(500)
            ->pushStatus(500)
            ->pushStatus(500),
        ]);

        $stream = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->stream('Go', provider: 'anthropic');

        $this->assertThrows(function () use ($stream): void {
            foreach ($stream as $event) {
            }
        }, RequestException::class);

        $row = $this->failedAssistantRow();

        $this->assertSame(MessageStatus::Failed->value, $row->status);
        $steps = json_decode($row->steps, true);
        $this->assertCount(1, $steps);
        $this->assertCount(1, $steps[0]['tool_calls']);
        $this->assertSame('toolu_1', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame('72019', $steps[0]['tool_calls'][0]['result']);
    }

    public function testAStreamThatDiesRecordsTheConversationTheClientWasAlreadyHanded(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->anthropicToolStream('toolu_1'), 200, ['Content-Type' => 'text/event-stream'])
            ->pushStatus(500)
            ->pushStatus(500)
            ->pushStatus(500),
        ]);

        $stream = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->stream('Go', provider: 'anthropic');

        $surfaced = $stream->conversationId;

        $this->assertThrows(function () use ($stream): void {
            foreach ($stream as $event) {
            }
        }, RequestException::class);

        $this->assertNotNull($surfaced);
        $this->assertSame($surfaced, DB::table('agent_conversations')->value('id'));
    }

    public function testATurnThatDiesKeepsTheTextItHadAlreadyProduced(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => 'Let me generate that number.'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
            ->pushStatus(500)
            ->pushStatus(500)
            ->pushStatus(500),
        ]);

        $this->assertThrows(fn () => (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt('Go', provider: 'anthropic'), RequestException::class);

        $this->assertSame('Let me generate that number.', $this->failedAssistantRow()->content);
    }

    public function testAStepThatDiesOnItsSecondCallKeepsTheResultOfTheFirst(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'SecretCodeGenerator', 'input' => (object) []],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $agent = (new RememberingFailingToolAgent)->forUser((object) ['id' => 1]);

        $this->assertThrows(fn () => $agent->prompt('Go', provider: 'anthropic'), Exception::class, 'Forced to throw exception.');

        $steps = json_decode($this->failedAssistantRow()->steps, true);
        $this->assertSame('toolu_1', $steps[0]['tool_calls'][0]['id']);
        $this->assertSame('ZEBRA-4417', $steps[0]['tool_calls'][0]['result']);
        $this->assertArrayNotHasKey('result', $steps[0]['tool_calls'][1]);

        $messages = (new DatabaseConversationStore)->getLatestConversationMessages(
            DB::table('agent_conversations')->value('id'),
            10,
        );

        $this->assertSame([
            'ZEBRA-4417',
            'This tool call was interrupted before a result was recorded, so it may or may not have run.',
        ], $messages->last()->toolResults->pluck('result')->all());
    }

    public function testAConversationOpenedByAFailedTurnIsTitledTheWayACompletedOneIs(): void
    {
        config(['ai.conversations.generate_title' => true]);

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->anthropicToolTurn('toolu_1'))
            ->pushStatus(500)
            ->pushStatus(500)
            ->pushStatus(500),
        ]);

        $prompt = 'Generate a number for the quarterly report and then explain how you arrived at it in detail';

        $this->assertThrows(fn () => (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt($prompt, provider: 'anthropic'), RequestException::class);

        $this->assertStringContainsString('arrived at it', DB::table('agent_conversations')->value('title'));
    }
}
