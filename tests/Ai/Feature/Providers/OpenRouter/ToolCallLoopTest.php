<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Attributes\MaxSteps;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolCallLoopTest extends TestCase
{
    /**
     * Define the test environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            '*' => Http::sequence([
                fakeOpenRouterToolCallResponse(),
                fakeOpenRouterResponse('The number is 72019'),
            ]),
        ]);

        $response = agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

        $this->assertSame('The number is 72019', $response->text);

        $requests = Http::recorded(fn (Request $r): true => true);
        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode((string) $requests[1][0]->body(), true);
        $messages = $followUpBody['messages'];

        $assistantMsg = collect($messages)->firstWhere('role', 'assistant');
        $this->assertNotNull($assistantMsg);
        $this->assertArrayHasKey('tool_calls', $assistantMsg);

        $toolMsg = collect($messages)->firstWhere('role', 'tool');
        $this->assertNotNull($toolMsg);
        $this->assertSame('call_123', $toolMsg['tool_call_id']);
    }

    public function testMaxStepsLimitsToolCallDepth(): void
    {
        Http::fake([
            '*' => Http::sequence([
                fakeOpenRouterToolCallResponse(),
                fakeOpenRouterToolCallResponse(),
                fakeOpenRouterToolCallResponse(),
                fakeOpenRouterResponse('Done'),
            ]),
        ]);

        $agent = new #[MaxSteps(3)] class implements Agent, HasTools {
            use Promptable;

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the agent tools.
             */
            public function tools(): iterable
            {
                return [new FixedNumberGenerator];
            }
        };

        $agent->prompt('Keep calling tools', provider: 'openrouter');

        $requests = Http::recorded(fn (Request $r): true => true);

        $this->assertLessThanOrEqual(3, count($requests));
    }

    public function testMultiStepToolLoopReturnsAccumulatedResponseShape(): void
    {
        Http::fake([
            '*' => Http::sequence([
                fakeOpenRouterToolCallResponse(),
                fakeOpenRouterToolCallResponse(),
                fakeOpenRouterResponse('Done'),
            ]),
        ]);

        $response = (new MultiStepToolAgent)->prompt('Generate numbers', provider: 'openrouter');

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
        Http::fake([
            '*' => Http::response([
                'id' => 'chatcmpl-tool-123',
                'object' => 'chat.completion',
                'model' => 'anthropic/claude-sonnet-4.6',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_123',
                            'type' => 'function',
                            'function' => ['name' => 'UnregisteredTool', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        $this->expectException(NoSuchToolException::class);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');
    }
}
