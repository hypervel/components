<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Ai\Responses\Data\ProviderToolCall;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\OpenAiAgent;
use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Hypervel\Tests\Ai\TestCase;

class ProviderToolCallsTest extends TestCase
{
    use OpenAiHelpers;

    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testProviderToolOutputItemsLandOnTheStep(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_1',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [
                    ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'hypervel ai']],
                    ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'ignored', 'arguments' => '{}'],
                    ['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Found it.']]],
                ],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
        ]);

        $response = (new OpenAiAgent)->prompt('Search');

        $this->assertCount(1, $response->steps[0]->providerToolCalls);
        $this->assertInstanceOf(ProviderToolCall::class, $response->steps[0]->providerToolCalls[0]);
        $this->assertSame([
            'id' => 'ws_1',
            'type' => 'web_search_call',
            'data' => ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'hypervel ai']],
        ], $response->steps[0]->providerToolCalls[0]->toArray());
    }

    public function testStreamedProviderToolItemsLandOnTheStep(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed']],
                    $this->outputTextDelta('Found it.'),
                    $this->outputTextDone('Found it.'),
                    ['type' => 'response.completed', 'response' => [
                        'id' => 'resp_1',
                        'model' => 'gpt-5.4',
                        'status' => 'completed',
                        'output' => [['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed']],
                        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                    ]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $streamEnd = collect(iterator_to_array((new OpenAiAgent)->stream('Search')))->first(fn (StreamEvent $event): bool => $event instanceof StreamEnd);

        $this->assertSame(['ws_1'], array_map(fn (ProviderToolCall $call): string => $call->id, $streamEnd->steps[0]->providerToolCalls));
    }
}
