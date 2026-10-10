<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NestedStructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class RequestMappingTest extends TestCase
{
    /**
     * Configure the xAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.xai.key', 'test-key');
    }

    public function testRequestIncludesModelAndInput(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'xai', model: 'grok-4-1-fast-reasoning');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'grok-4-1-fast-reasoning'
                && is_array($body['input'])
                && collect($body['input'])->contains(fn ($m): bool => $m['role'] === 'user'
                    && collect($m['content'])->contains(fn ($c): bool => ($c['type'] ?? '') === 'input_text' && $c['text'] === 'Hi there'));
        });
    }

    public function testSystemInstructionsAreSentAsSystemMessage(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['input'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'helpful assistant');
        });
    }

    public function testTemperatureAndMaxTokensAreIncludedWhenSetViaAttributes(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_output_tokens') === 4096;
        });
    }

    public function testTemperatureAndMaxTokensAreExcludedWhenNotSet(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

        agent()->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_output_tokens', $body);
        });
    }

    public function testToolsIncludeToolChoiceAuto(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && $body['tools'] !== [];
        });
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

        agent()->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function testRequiredToolChoiceForcesTheModelToCallATool(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('42')]);

        (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'xai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('42')]);

        (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'xai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testNamedToolChoiceForcesASpecificFunction(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('42')]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'xai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === [
            'type' => 'function',
            'name' => 'custom_named_tool',
        ]);
    }

    public function testNoneToolChoicePreventsToolCalls(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('Sure')]);

        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'xai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
    }

    public function testStructuredOutputIncludesJsonSchemaTextFormat(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $format = data_get($body, 'text.format');

            return $format['type'] === 'json_schema'
                && isset($format['name'], $format['schema'])

                && $format['strict'] === true;
        });
    }

    public function testStructuredOutputWithoutStrictAttributeSendsStrictFalseInTextFormat(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('{"elements": []}')]);

        (new NestedStructuredAgent)->prompt('List elements.', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $format = data_get(json_decode($request->body(), true), 'text.format');

            return $format['type'] === 'json_schema'
                && $format['strict'] === false;
        });
    }

    public function testRequestWithoutSchemaExcludesTextFormat(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

        agent()->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('text', $body);
        });
    }

    public function testStreamingRequestIncludesStreamFlag(): void
    {
        $sseData = "data: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_123\",\"model\":\"grok-4-1-fast-reasoning\"}}\n\n"
            . "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hi\"}\n\n"
            . "data: {\"type\":\"response.output_text.done\"}\n\n"
            . "data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_123\",\"usage\":{\"input_tokens\":1,\"output_tokens\":1}}}\n\n";

        Http::fake(['*' => Http::response($sseData)]);

        $stream = agent()->stream('Hello', provider: 'xai');

        foreach ($stream as $event);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true;
        });
    }

    public function testRequestSendsBearerTokenAuthorization(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

        agent()->prompt('Hello', provider: 'xai');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('Hypervel is great')]);

        $response = agent()->prompt('Tell me about Hypervel', provider: 'xai');

        $this->assertSame('Hypervel is great', $response->text);
        $this->assertSame('xai', $response->meta->provider);
    }

    public function testResponseTextIncludesEveryPartInEveryMessage(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'completed',
            'model' => 'grok-4',
            'output' => [
                ['type' => 'message', 'status' => 'completed', 'content' => [
                    ['type' => 'output_text', 'text' => 'First '],
                    ['type' => 'output_text', 'text' => 'message. '],
                ]],
                ['type' => 'message', 'status' => 'completed', 'content' => [
                    ['type' => 'output_text', 'text' => 'Second message.'],
                ]],
            ],
        ])]);

        $this->assertSame('First message. Second message.', agent()->prompt('Hello', provider: 'xai')->text);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [
                [
                    'type' => 'message',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => 'Hello'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'input_tokens_details' => ['cached_tokens' => 2],
                'output_tokens_details' => ['reasoning_tokens' => 3],
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'xai');

        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(8, $response->usage->outputTokens);
        $this->assertSame(2, $response->usage->cacheReadInputTokens);
        $this->assertSame(3, $response->usage->reasoningTokens);
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeXaiRequestMappingResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'xai');

        $this->assertSame('Au', $response->structured['symbol']);
    }

    public function testCitationsPreserveEveryAnnotationWithSpanIndices(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Here are sources',
                    'annotations' => [
                        [
                            'type' => 'url_citation',
                            'url' => 'https://example.com/one',
                            'title' => 'Same Title',
                            'start_index' => 0,
                            'end_index' => 10,
                        ],
                        [
                            'type' => 'url_citation',
                            'url' => 'https://example.com/two',
                            'title' => 'Same Title',
                            'start_index' => 11,
                            'end_index' => 25,
                        ],
                        [
                            'type' => 'url_citation',
                            'url' => 'https://example.com/one',
                            'title' => 'Same Title',
                            'start_index' => 26,
                            'end_index' => 40,
                        ],
                    ],
                ]],
            ]],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ])]);

        $response = agent()->prompt('Give me sources', provider: 'xai');

        $this->assertCount(3, $response->meta->citations);
        $this->assertSame('https://example.com/one', $response->meta->citations[0]->url);
        $this->assertSame(0, $response->meta->citations[0]->startIndex);
        $this->assertSame(10, $response->meta->citations[0]->endIndex);
        $this->assertSame('https://example.com/two', $response->meta->citations[1]->url);
        $this->assertSame(11, $response->meta->citations[1]->startIndex);
        $this->assertSame('https://example.com/one', $response->meta->citations[2]->url);
        $this->assertSame(26, $response->meta->citations[2]->startIndex);
    }

    public function testCitationsOmitSpanIndicesWhenNotProvidedByTheApi(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Sources',
                    'annotations' => [
                        [
                            'type' => 'url_citation',
                            'url' => 'https://example.com/a',
                            'title' => 'A',
                        ],
                    ],
                ]],
            ]],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ])]);

        $response = agent()->prompt('Give me sources', provider: 'xai');

        $this->assertCount(1, $response->meta->citations);
        $this->assertSame('https://example.com/a', $response->meta->citations[0]->url);
        $this->assertNull($response->meta->citations[0]->startIndex);
        $this->assertNull($response->meta->citations[0]->endIndex);
    }
}

/**
 * Create a completed text response.
 */
function fakeXaiRequestMappingResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ]);
}
