<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeDeepSeekResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolCallLoopTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.deepseek.key', 'test-key');
    }

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence([
                $this->fakeUniqueDeepSeekToolCallResponse(),
                fakeDeepSeekResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'deepseek',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $hasAssistantWithToolCalls = false;
        $hasToolResult = false;

        foreach ($followUpBody['messages'] as $message) {
            if ($message['role'] === 'assistant' && isset($message['tool_calls'])) {
                $hasAssistantWithToolCalls = true;
            }

            if ($message['role'] === 'tool') {
                $hasToolResult = true;
            }
        }

        $this->assertTrue($hasAssistantWithToolCalls);
        $this->assertTrue($hasToolResult);
    }

    public function testMaxStepsLimitsToolCallDepth(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence([
                $this->fakeUniqueDeepSeekToolCallResponse(),
                $this->fakeUniqueDeepSeekToolCallResponse(),
                $this->fakeUniqueDeepSeekToolCallResponse(),
                fakeDeepSeekResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'deepseek',
        );

        $recorded = Http::recorded();

        $this->assertLessThanOrEqual(3, count($recorded));
    }

    public function testMultiStepToolLoopReturnsAccumulatedResponseShape(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence([
                $this->fakeUniqueDeepSeekToolCallResponse(),
                $this->fakeUniqueDeepSeekToolCallResponse(),
                fakeDeepSeekResponse('Done'),
            ]),
        ]);

        $response = (new MultiStepToolAgent)->prompt(
            'Generate numbers',
            provider: 'deepseek',
        );

        $this->assertSame('Done', (string) $response);
        $this->assertCount(5, $response->messages);
        $this->assertCount(3, $response->steps);
        $this->assertCount(2, $response->toolCalls);
        $this->assertCount(2, $response->toolResults);
        $this->assertSame(21, $response->usage->inputTokens);
        $this->assertSame(11, $response->usage->outputTokens);
    }

    public function testUnknownToolCallThrowsNoSuchToolException(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'id' => 'chatcmpl-tool-unknown',
                'object' => 'chat.completion',
                'model' => 'deepseek-chat',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_unknown',
                            'type' => 'function',
                            'function' => [
                                'name' => 'UnregisteredTool',
                                'arguments' => '{}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ]),
        ]);

        $this->expectException(NoSuchToolException::class);
        (new MultiStepToolAgent)->prompt(
            'Generate numbers',
            provider: 'deepseek',
        );
    }

    /**
     * Create a tool-call response with unique response and call identifiers.
     */
    protected function fakeUniqueDeepSeekToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-tool-' . uniqid(),
            'object' => 'chat.completion',
            'model' => 'deepseek-chat',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_' . uniqid(),
                        'type' => 'function',
                        'function' => [
                            'name' => 'FixedNumberGenerator',
                            'arguments' => '{}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }
}
