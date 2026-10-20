<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Responses\Data\ProviderToolCall;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

class ProviderToolCallsTest extends TestCase
{
    public function testServerToolUseAndResultBlocksLandOnTheStepKeyedByTheToolUse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'hypervel ai']],
                    ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                    ['type' => 'text', 'text' => 'Found it.'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Search', provider: 'anthropic');

        $this->assertSame([['srvtoolu_1', 'server_tool_use'], ['srvtoolu_1', 'web_search_tool_result']], array_map(fn (ProviderToolCall $call): array => [$call->id, $call->type], $response->steps[0]->providerToolCalls));
        $this->assertSame(['query' => 'hypervel ai'], $response->steps[0]->providerToolCalls[0]->data['input']);
    }

    public function testTextBeforeAndAfterAServerToolIsSeparatedByABlankLineWhileCitationSplitTextStaysJoined(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => 'Let me check.'],
                    ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'hypervel ai']],
                    ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                    ['type' => 'text', 'text' => 'The latest stable version is '],
                    ['type' => 'text', 'text' => 'Hypervel 0.4', 'citations' => [['type' => 'web_search_result_location', 'url' => 'https://hypervel.org', 'title' => 'Hypervel', 'cited_text' => 'Hypervel 0.4']]],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Search', provider: 'anthropic');

        $this->assertSame("Let me check.\n\nThe latest stable version is Hypervel 0.4", $response->text);
    }

    public function testTextAroundARedactedThinkingBlockIsNotSeparated(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => 'First'],
                    ['type' => 'redacted_thinking', 'data' => 'encrypted'],
                    ['type' => 'text', 'text' => 'Second'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hello', provider: 'anthropic');

        $this->assertSame('FirstSecond', $response->text);
    }

    public function testTextAfterAServerToolIsNotSeparatedWhenTheModelAlreadyEndedTheLine(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => "Let me check.\n"],
                    ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'hypervel ai']],
                    ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                    ['type' => 'text', 'text' => 'Done'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Search', provider: 'anthropic');

        $this->assertSame("Let me check.\nDone", $response->text);
    }
}
