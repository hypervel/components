<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\OpenAiAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class EncryptedReasoningTest extends TestCase
{
    use OpenAiHelpers;

    /**
     * Configure stateless OpenAI requests.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set([
            'ai.providers.openai.key' => 'test-key',
            'ai.providers.openai.store' => false,
        ]);
    }

    public function testInitialRequestIncludesStoreFalseAndReasoningEncryptedContentInInclude(): void
    {
        Http::fake([
            'api.openai.com/*' => fakeOpenAiResponse('Hello'),
        ]);

        (new OpenAiAgent)->prompt('Hello');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode((string) $request->body(), true);

            return ($body['store'] ?? null) === false
                && in_array('reasoning.encrypted_content', $body['include'] ?? [], true);
        });
    }

    public function testToolFollowUpOmitsPreviousResponseIdAndEchoesEncryptedReasoningBackInline(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
                fakeOpenAiResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        $followUp = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertArrayNotHasKey('previous_response_id', $followUp);
        $this->assertFalse($followUp['store'] ?? null);
        $this->assertContains('reasoning.encrypted_content', $followUp['include'] ?? []);

        $input = collect($followUp['input']);

        $this->assertTrue($input->contains(fn (array $item): bool => ($item['role'] ?? null) === 'user'
            && collect($item['content'] ?? [])->contains(fn (array $content): bool => ($content['text'] ?? '') === 'Generate a number')), 'original user message resent inline');
        $this->assertTrue($input->contains(fn (array $item): bool => ($item['type'] ?? null) === 'reasoning'
            && ($item['id'] ?? null) === 'rs_1'
            && ($item['encrypted_content'] ?? null) === 'enc-blob-1'), 'reasoning block with encrypted_content round-tripped');
        $this->assertTrue($input->contains(fn (array $item): bool => ($item['type'] ?? null) === 'function_call'
            && ($item['call_id'] ?? null) === 'call_1'), 'assistant function call resent');
        $this->assertTrue($input->contains(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'
            && ($item['call_id'] ?? null) === 'call_1'), 'tool result included');
    }

    public function testMultiStepToolLoopAccumulatesEncryptedReasoningAcrossSteps(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
                fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_2', 'enc-blob-2', 'fc_2', 'call_2'),
                fakeOpenAiResponse('Done'),
            ]),
        ]);

        (new MultiStepToolAgent)->prompt('Generate a number', provider: 'openai');

        $recorded = Http::recorded();
        $this->assertCount(3, $recorded);

        $finalFollowUp = json_decode((string) $recorded[2][0]->body(), true);

        $this->assertArrayNotHasKey('previous_response_id', $finalFollowUp);

        $input = collect($finalFollowUp['input']);

        $this->assertCount(1, $input->where(fn (array $item): bool => ($item['type'] ?? null) === 'reasoning'
            && ($item['encrypted_content'] ?? null) === 'enc-blob-1'));
        $this->assertCount(1, $input->where(fn (array $item): bool => ($item['type'] ?? null) === 'reasoning'
            && ($item['encrypted_content'] ?? null) === 'enc-blob-2'));
        $this->assertCount(2, $input->where(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'));
    }

    public function testStreamingToolFollowUpEchoesEncryptedReasoningBackInline(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->responseCreated(),
                        [
                            'type' => 'response.output_item.done',
                            'item' => [
                                'type' => 'reasoning',
                                'id' => 'rs_1',
                                'summary' => [],
                                'encrypted_content' => 'enc-blob-1',
                            ],
                        ],
                        $this->outputItemAdded('fc_1', 'call_1', 'FixedNumberGenerator'),
                        $this->functionCallArgumentsDelta('fc_1', '{}'),
                        $this->functionCallArgumentsDone('fc_1', '{}'),
                        $this->responseCompleted(10, 5, output: [
                            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
                            ['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                        ]),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        [
                            'type' => 'response.created',
                            'response' => ['id' => 'resp_2', 'model' => 'gpt-5.4', 'status' => 'in_progress', 'output' => []],
                        ],
                        $this->outputTextDelta('Done'),
                        $this->outputTextDone('Done'),
                        $this->responseCompleted(20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        foreach ((new ProviderOptionsWithToolsAgent)->stream('Generate a number', provider: 'openai') as $event);

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        $followUp = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertArrayNotHasKey('previous_response_id', $followUp);
        $this->assertFalse($followUp['store'] ?? null);
        $this->assertContains('reasoning.encrypted_content', $followUp['include'] ?? []);

        $input = collect($followUp['input']);

        $this->assertTrue($input->contains(fn (array $item): bool => ($item['type'] ?? null) === 'reasoning'
            && ($item['id'] ?? null) === 'rs_1'
            && ($item['encrypted_content'] ?? null) === 'enc-blob-1'), 'streamed reasoning block with encrypted_content round-tripped');
        $this->assertTrue($input->contains(fn (array $item): bool => ($item['type'] ?? null) === 'function_call'
            && ($item['call_id'] ?? null) === 'call_1'), 'streamed function call resent');
        $this->assertTrue($input->contains(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'
            && ($item['call_id'] ?? null) === 'call_1'), 'streamed tool result included');
    }

    #[DataProvider('nonReasoningModels')]
    public function testNonReasoningModelOmitsReasoningEncryptedContentIncludeEvenWithStoreFalse(string $model): void
    {
        Http::fake(['api.openai.com/*' => fakeOpenAiResponse()]);

        (new OpenAiAgent)->prompt('Hi', model: $model);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode((string) $request->body(), true);

            return ($body['store'] ?? null) === false
                && ! in_array('reasoning.encrypted_content', $body['include'] ?? [], true);
        });
    }

    /**
     * Provide models without reasoning support.
     */
    public static function nonReasoningModels(): array
    {
        return [['gpt-4.1'], ['gpt-4o'], ['gpt-5-chat-latest']];
    }

    #[DataProvider('storeValues')]
    public function testStoreAcceptsEnvStyleStringValues(bool|string $storeValue, bool $shouldBeStateless): void
    {
        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'store' => $storeValue,
        ]]);

        Http::fake(['api.openai.com/*' => fakeOpenAiResponse()]);

        (new OpenAiAgent)->prompt('Hi');

        Http::assertSent(function (Request $request) use ($shouldBeStateless): bool {
            $body = json_decode((string) $request->body(), true);
            $isStateless = ($body['store'] ?? null) === false;

            return $isStateless === $shouldBeStateless;
        });
    }

    /**
     * Provide response storage configuration values.
     */
    public static function storeValues(): array
    {
        return [
            'bool false' => [false, true],
            'string "false"' => ['false', true],
            'string "0"' => ['0', true],
            'string "no"' => ['no', true],
            'bool true' => [true, false],
            'string "true"' => ['true', false],
            'unrecognized string' => ['maybe', false],
        ];
    }

    public function testDefaultStoreTruePreservesPreviousResponseIdBehavior(): void
    {
        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'store' => true,
        ]]);

        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
                fakeOpenAiResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

        $recorded = Http::recorded();
        $followUp = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUp);
        $this->assertSame('resp_tool_1', $followUp['previous_response_id']);
        $this->assertArrayNotHasKey('store', $followUp);
        $this->assertContains('reasoning.encrypted_content', $followUp['include'] ?? []);

        $input = collect($followUp['input']);

        $this->assertCount(1, $input);
        $this->assertSame('function_call_output', $input->first()['type'] ?? null);
        $this->assertSame('call_1', $input->first()['call_id'] ?? null);
        $this->assertFalse($input->contains(fn (array $item): bool => ($item['role'] ?? null) === 'user'));
        $this->assertFalse($input->contains(fn (array $item): bool => ($item['type'] ?? null) === 'reasoning'));
    }

    public function testStatelessReplayPreservesTheOriginalOrderOfReasoningHostedToolAndFunctionCallItems(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                Http::response([
                    'id' => 'resp_tool_1',
                    'status' => 'completed',
                    'model' => 'gpt-5.4',
                    'output' => [
                        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
                        ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
                        ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}', 'status' => 'completed'],
                    ],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                fakeOpenAiResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

        $input = collect(json_decode(Http::recorded()[1][0]->body(), true)['input']);

        $reasoningAt = $input->search(fn (array $item): bool => ($item['type'] ?? null) === 'reasoning');
        $hostedAt = $input->search(fn (array $item): bool => ($item['type'] ?? null) === 'web_search_call');
        $functionAt = $input->search(fn (array $item): bool => ($item['type'] ?? null) === 'function_call');

        $this->assertNotFalse($reasoningAt);
        $this->assertNotFalse($hostedAt);
        $this->assertNotFalse($functionAt);
        $this->assertLessThan($hostedAt, $reasoningAt);
        $this->assertLessThan($functionAt, $hostedAt);
        $this->assertSame('enc-blob-1', $input->firstWhere('type', 'reasoning')['encrypted_content'] ?? null);
        $this->assertTrue($input->contains(fn (array $item): bool => ($item['type'] ?? null) === 'function_call_output'
            && ($item['call_id'] ?? null) === 'call_1'));
    }

    public function testStatelessStoreFalseResponsesCaptureReplayBlocks(): void
    {
        Http::fake(['api.openai.com/*' => fakeOpenAiResponse('Hi')]);

        $response = (new OpenAiAgent)->prompt('Hello', provider: 'openai');

        $this->assertNotEmpty($response->messages->whereInstanceOf(AssistantMessage::class)->last()->replayBlocks);
    }

    public function testDefaultStoreTrueStillRetainsReplayBlocksWithEncryptedReasoning(): void
    {
        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'store' => true,
        ]]);

        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiToolCallResponseWithEncryptedReasoning('rs_1', 'enc-blob-1', 'fc_1', 'call_1'),
                fakeOpenAiResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

        $blocks = $response->messages->whereInstanceOf(AssistantMessage::class)->first()->replayBlocks;

        $reasoning = collect($blocks)->firstWhere('type', 'reasoning');
        $this->assertSame('rs_1', $reasoning['id']);
        $this->assertSame('enc-blob-1', $reasoning['encrypted_content']);
        $this->assertSame('call_1', collect($blocks)->firstWhere('type', 'function_call')['call_id'] ?? null);
    }

    public function testStatelessToolFollowUpDropsFileSearchCallsButKeepsTheSurroundingReasoning(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                Http::response([
                    'id' => 'resp_tool_1',
                    'status' => 'completed',
                    'model' => 'gpt-5.4',
                    'output' => [
                        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
                        ['type' => 'file_search_call', 'id' => 'fs_1', 'status' => 'completed', 'queries' => ['numbers'], 'results' => null],
                        ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}', 'status' => 'completed'],
                    ],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                fakeOpenAiResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

        $input = collect(json_decode((string) Http::recorded()[1][0]->body(), true)['input']);

        $this->assertSame(['reasoning', 'function_call', 'function_call_output'], $input->whereNotNull('type')->pluck('type')->all());
        $reasoning = $input->firstWhere('type', 'reasoning');
        $this->assertSame('rs_1', $reasoning['id']);
        $this->assertSame('enc-blob-1', $reasoning['encrypted_content']);

        $blocks = $response->messages->whereInstanceOf(AssistantMessage::class)->first()->replayBlocks;

        $this->assertSame('fs_1', collect($blocks)->firstWhere('type', 'file_search_call')['id'] ?? null);
    }
}

function fakeOpenAiToolCallResponseWithEncryptedReasoning(string $reasoningId, string $encryptedContent, string $functionCallId, string $callId): PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_1',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [
            [
                'type' => 'reasoning',
                'id' => $reasoningId,
                'summary' => [],
                'encrypted_content' => $encryptedContent,
            ],
            [
                'type' => 'function_call',
                'id' => $functionCallId,
                'call_id' => $callId,
                'name' => 'FixedNumberGenerator',
                'arguments' => '{}',
                'status' => 'completed',
            ],
        ],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}
