<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\OllamaHelpers;
use Hypervel\Tests\Ai\TestCase;

class ToolCallLoopTest extends TestCase
{
    use OllamaHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.ollama.key', '');
    }

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeUniqueOllamaToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'ollama',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpMessages = collect(json_decode((string) $recorded[1][0]->body(), true)['messages']);

        $this->assertTrue($followUpMessages->contains(fn (array $m): bool => $m['role'] === 'assistant' && isset($m['tool_calls'])));
        $this->assertTrue($followUpMessages->contains(fn (array $m): bool => $m['role'] === 'tool'));
    }

    public function testToolResultMessageUsesToolNameField(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeUniqueOllamaToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'ollama',
        );

        $recorded = Http::recorded();
        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $toolMsg = collect($followUpBody['messages'])->first(fn (array $m): bool => $m['role'] === 'tool');

        $this->assertNotNull($toolMsg);
        $this->assertArrayHasKey('tool_name', $toolMsg);
        $this->assertSame('FixedNumberGenerator', $toolMsg['tool_name']);
        $this->assertArrayNotHasKey('tool_call_id', $toolMsg);
    }

    public function testMaxStepsLimitsToolCallDepth(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeUniqueOllamaToolCallResponse(),
                $this->fakeUniqueOllamaToolCallResponse(),
                $this->fakeUniqueOllamaToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'ollama',
        );

        $recorded = Http::recorded();

        $this->assertLessThanOrEqual(3, count($recorded));
    }

    public function testToolCallsWithoutIdAreExecutedWithAGeneratedId(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            // No "id" field — some Ollama models omit it.
                            'function' => [
                                'name' => 'FixedNumberGenerator',
                                'arguments' => (object) [],
                            ],
                        ]],
                    ],
                    'done_reason' => 'tool_calls',
                    'done' => true,
                    'prompt_eval_count' => 10,
                    'eval_count' => 5,
                ]),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'ollama',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $toolMsg = collect($followUpBody['messages'])->first(fn (array $m): bool => $m['role'] === 'tool');

        $this->assertNotNull($toolMsg);
        $this->assertSame('FixedNumberGenerator', $toolMsg['tool_name']);
    }

    public function testToolCallsAreExecutedEvenWhenDoneReasonIsStop(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_123',
                            'function' => [
                                'name' => 'FixedNumberGenerator',
                                'arguments' => (object) [],
                            ],
                        ]],
                    ],
                    // Real Ollama responses can report "stop" even when tool_calls
                    // are populated.
                    'done_reason' => 'stop',
                    'done' => true,
                    'prompt_eval_count' => 10,
                    'eval_count' => 5,
                ]),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'ollama',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);
        $this->assertSame('The number is 72019', $response->text);
    }

    public function testToolCallsAreNotExecutedWhenDoneReasonIsLength(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response([
                    'model' => 'llama3.1:8b',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_123',
                            'function' => [
                                'name' => 'FixedNumberGenerator',
                                'arguments' => (object) [],
                            ],
                        ]],
                    ],
                    'done_reason' => 'length',
                    'done' => true,
                    'prompt_eval_count' => 10,
                    'eval_count' => 5,
                ]),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'ollama',
        );

        Http::assertSentCount(1);
        $this->assertCount(1, $response->steps);
        $this->assertSame(FinishReason::Length, $response->steps[0]->finishReason);
        $this->assertCount(0, $response->toolResults);
    }

    public function testMultiStepToolLoopReturnsAccumulatedResponseShape(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeUniqueOllamaToolCallResponse(),
                $this->fakeUniqueOllamaToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new MultiStepToolAgent)->prompt(
            'Generate numbers',
            provider: 'ollama',
        );

        $this->assertSame('Done', (string) $response);
        $this->assertCount(5, $response->messages);
        $this->assertCount(3, $response->steps);
        $this->assertCount(2, $response->toolCalls);
        $this->assertCount(2, $response->toolResults);
        $this->assertSame(21, $response->usage->inputTokens);
        $this->assertSame(11, $response->usage->outputTokens);
    }

    public function testUnregisteredToolCallThrowsNoSuchToolException(): void
    {
        $this->expectException(NoSuchToolException::class);
        Http::fake([
            '*' => Http::response([
                'model' => 'llama3.1:8b',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_123',
                        'function' => [
                            'name' => 'UnregisteredTool',
                            'arguments' => (object) [],
                        ],
                    ]],
                ],
                'done_reason' => 'tool_calls',
                'done' => true,
                'prompt_eval_count' => 10,
                'eval_count' => 5,
            ]),
        ]);

        (new MultiStepToolAgent)->prompt(
            'Generate a number',
            provider: 'ollama',
        );
    }

    /**
     * Create a tool response with a unique call ID.
     */
    protected function fakeUniqueOllamaToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'model' => 'llama3.1:8b',
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_' . uniqid(),
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => (object) [],
                    ],
                ]],
            ],
            'done_reason' => 'tool_calls',
            'done' => true,
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ]);
    }
}
