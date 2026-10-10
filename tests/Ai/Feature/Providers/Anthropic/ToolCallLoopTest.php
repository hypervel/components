<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;
use stdClass;

class ToolCallLoopTest extends TestCase
{
    use AnthropicHelpers;

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = $recorded[1][0]->data();

        $hasAssistantWithToolUse = false;
        $hasToolResult = false;

        foreach ($followUpBody['messages'] as $message) {
            if ($message['role'] === 'assistant') {
                foreach ($message['content'] as $block) {
                    if (($block['type'] ?? '') === 'tool_use') {
                        $hasAssistantWithToolUse = true;
                    }
                }
            }

            if ($message['role'] === 'user') {
                foreach ($message['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $hasToolResult = true;
                    }
                }
            }
        }

        $this->assertTrue($hasAssistantWithToolUse, 'Follow-up request should include assistant message with tool_use block');
        $this->assertTrue($hasToolResult, 'Follow-up request should include user message with tool_result block');
    }

    public function testServerToolUseInputIsSerializedAsObjectOnFollowUpReplay(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_123',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        ['type' => 'server_tool_use', 'id' => 'srvtoolu_123', 'name' => 'advisor', 'input' => (object) []],
                        ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'anthropic');

        $followUpBody = Http::recorded()[1][0]->body();

        $this->assertStringContainsString('"type":"server_tool_use"', $followUpBody);
        $this->assertStringNotContainsString('"input":[]', $followUpBody);
    }

    public function testPauseTurnResumesWhenLastBlockIsTextFollowingAWebSearchToolResult(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_pause',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        [
                            'type' => 'server_tool_use',
                            'id' => 'srvtoolu_1',
                            'name' => 'web_search',
                            'input' => (object) ['query' => 'q'],
                        ],
                        [
                            'type' => 'web_search_tool_result',
                            'tool_use_id' => 'srvtoolu_1',
                            'content' => [],
                        ],
                        ['type' => 'text', 'text' => 'Paused mid-summary.'],
                    ],
                    'stop_reason' => 'pause_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Ask',
            provider: 'anthropic',
        );

        $this->assertCount(2, Http::recorded());
    }

    public function testFullComposePauseTurnAndServerToolUseReplayWithInputCastAndBlockOrderPreserved(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_compose',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        [
                            'type' => 'server_tool_use',
                            'id' => 'srvtoolu_1',
                            'name' => 'advisor',
                            'input' => (object) [],
                        ],
                        [
                            'type' => 'advisor_tool_result',
                            'tool_use_id' => 'srvtoolu_1',
                            'content' => ['type' => 'advisor_result', 'text' => 'Plan.'],
                        ],
                        ['type' => 'text', 'text' => 'Consulting again.'],
                        [
                            'type' => 'server_tool_use',
                            'id' => 'srvtoolu_2',
                            'name' => 'advisor',
                            'input' => (object) [],
                        ],
                    ],
                    'stop_reason' => 'pause_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Compose test',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        $payload = json_decode((string) $recorded[1][0]->body(), false, 512, JSON_THROW_ON_ERROR);
        $assistant = collect($payload->messages)->first(fn ($m): bool => $m->role === 'assistant');

        $this->assertSame([
            'server_tool_use',
            'advisor_tool_result',
            'text',
            'server_tool_use',
        ], array_column((array) $assistant->content, 'type'));

        $serverBlocks = collect($assistant->content)->where('type', 'server_tool_use')->values();
        $this->assertCount(2, $serverBlocks);
        $this->assertInstanceOf(stdClass::class, $serverBlocks[0]->input);
        $this->assertInstanceOf(stdClass::class, $serverBlocks[1]->input);
    }

    public function testMaxStepsLimitsToolCallDepth(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();

        $this->assertLessThanOrEqual(3, count($recorded));
    }
}
