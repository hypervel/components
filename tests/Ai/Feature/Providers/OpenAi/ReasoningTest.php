<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\OpenAiAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiReasonedResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiReasonedToolCallResponse;
use function Hypervel\Tests\Ai\Fixtures\openAiReasoningItem;
use function Hypervel\Tests\Ai\Fixtures\openAiReasoningItemWithBoth;
use function Hypervel\Tests\Ai\Fixtures\openAiReasoningTextItem;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ReasoningTest extends TestCase
{
    use RefreshDatabase;
    use OpenAiHelpers;

    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testPromptJoinsReasoningBlocksExactlyAsAStreamOfTheSameReasoningDoes(): void
    {
        $items = [
            openAiReasoningItem('rs_1', 'Let me ', 'think...'),
            openAiReasoningItem('rs_2', 'Now I am sure.'),
        ];

        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiReasonedResponse($items, 'Answer'),
                Http::response(
                    body: $this->ssePayload([
                        $this->responseCreated(),
                        ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Let me ', 'item_id' => 'rs_1'],
                        ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'think...', 'item_id' => 'rs_1'],
                        ['type' => 'response.output_item.done', 'item' => $items[0]],
                        ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Now I am sure.', 'item_id' => 'rs_2'],
                        ['type' => 'response.output_item.done', 'item' => $items[1]],
                        $this->outputTextDelta('Answer'),
                        $this->outputTextDone('Answer'),
                        $this->responseCompleted(1, 1),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $prompted = (new OpenAiAgent)->prompt('Hello');

        $streamed = (new OpenAiAgent)->stream('Hello');
        iterator_to_array($streamed);

        $this->assertSame("Let me think...\n\nNow I am sure.", $prompted->reasoning);
        $this->assertSame($streamed->reasoning, $prompted->reasoning);
    }

    public function testPromptReadsRawReasoningTextExactlyAsAStreamOfTheSameReasoningDoes(): void
    {
        $item = openAiReasoningTextItem('rs_1', 'Raw ', 'thoughts.');

        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiReasonedResponse([$item], 'Answer'),
                Http::response(
                    body: $this->ssePayload([
                        $this->responseCreated(),
                        ['type' => 'response.reasoning_text.delta', 'delta' => 'Raw ', 'item_id' => 'rs_1'],
                        ['type' => 'response.reasoning_text.delta', 'delta' => 'thoughts.', 'item_id' => 'rs_1'],
                        ['type' => 'response.output_item.done', 'item' => $item],
                        $this->outputTextDelta('Answer'),
                        $this->outputTextDone('Answer'),
                        $this->responseCompleted(1, 1),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $prompted = (new OpenAiAgent)->prompt('Hello');

        $streamed = (new OpenAiAgent)->stream('Hello');
        iterator_to_array($streamed);

        $this->assertSame('Raw thoughts.', $prompted->reasoning);
        $this->assertSame($streamed->reasoning, $prompted->reasoning);
    }

    public function testASummaryAndTheRawReasoningOfOneItemStaySeparateBlocks(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiReasonedResponse([
                openAiReasoningItemWithBoth('rs_1', 'I weighed the options.', 'Raw thoughts.'),
            ], 'Answer'),
        ]);

        $this->assertSame("I weighed the options.\n\nRaw thoughts.", (new OpenAiAgent)->prompt('Hello')->reasoning);
    }

    public function testReasoningIsJoinedAcrossEveryStepOfAToolCallingTurn(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiReasonedToolCallResponse([openAiReasoningItem('rs_0', 'I need a number.')]),
                fakeOpenAiReasonedResponse([openAiReasoningItem('rs_1', 'The tool answered.')], 'The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

        $this->assertSame("I need a number.\n\nThe tool answered.", $response->reasoning);
        $this->assertSame(['I need a number.', 'The tool answered.'], $response->steps->pluck('reasoning')->all());
    }

    public function testAStructuredResponseCarriesTheReasoningThatProducedIt(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiReasonedResponse(
                [openAiReasoningItem('rs_1', 'Gold is Au.')],
                '{"symbol": "Au"}',
            ),
        ]);

        $response = (new StructuredAgent)->prompt('Symbol for gold?', provider: 'openai');

        $this->assertSame('Gold is Au.', $response->reasoning);
        $this->assertSame(['symbol' => 'Au'], $response->structured);
    }

    public function testAResponsePausedOnAToolApprovalCarriesTheReasoningSoFar(): void
    {
        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_tool_123',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [
                    openAiReasoningItem('rs_1', 'I need approval first.'),
                    [
                        'type' => 'function_call',
                        'id' => 'fc_123',
                        'call_id' => 'call_123',
                        'name' => 'ApprovableNumberGenerator',
                        'arguments' => '{}',
                        'status' => 'completed',
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $paused = (new RememberingApprovableAgent)
            ->forUser((object) ['id' => 1])
            ->prompt('Generate a number', provider: 'openai');

        $this->assertTrue($paused->hasPendingApprovals());
        $this->assertSame('I need approval first.', $paused->reasoning);
    }
}
