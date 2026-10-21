<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

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
use function Hypervel\Tests\Ai\Fixtures\fakeOpenRouterResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class RequestMappingTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testRequestIncludesModelAndMessages(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'openrouter', model: 'anthropic/claude-sonnet-4.6');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'anthropic/claude-sonnet-4.6'
                && count($body['messages']) >= 1
                && collect($body['messages'])->contains(fn (array $m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
        });
    }

    public function testSystemInstructionsAreSentAsSystemMessage(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'helpful assistant');
        });
    }

    public function testTemperatureAndMaxTokensAreIncludedWhenSetViaAttributes(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_tokens') === 4096;
        });
    }

    public function testTemperatureAndMaxTokensAreExcludedWhenNotSet(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_tokens', $body);
        });
    }

    public function testToolsIncludeToolChoiceAuto(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && $body['tools'] !== [];
        });
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function testRequiredToolChoiceForcesTheModelToCallATool(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('42')]);

        (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('42')]);

        (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testNamedToolChoiceForcesASpecificFunction(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('42')]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === [
            'type' => 'function',
            'function' => ['name' => 'custom_named_tool'],
        ]);
    }

    public function testNoneToolChoicePreventsToolCalls(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Sure')]);

        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
    }

    public function testStructuredOutputIncludesJsonSchemaResponseFormat(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $format = data_get($body, 'response_format');

            return $format['type'] === 'json_schema'
                && isset($format['json_schema']['name'], $format['json_schema']['schema'])

                && $format['json_schema']['strict'] === true;
        });
    }

    public function testStructuredOutputWithoutStrictAttributeSendsStrictFalseInResponseFormat(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('{"elements": []}')]);

        (new NestedStructuredAgent)->prompt('List elements.', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $format = data_get(json_decode($request->body(), true), 'response_format');

            return $format['type'] === 'json_schema'
                && $format['json_schema']['strict'] === false;
        });
    }

    public function testRequestWithoutSchemaExcludesResponseFormat(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('response_format', $body);
        });
    }

    public function testStreamingRequestIncludesStreamOptions(): void
    {
        Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

        $stream = agent()->stream('Hello', provider: 'openrouter');

        foreach ($stream as $event);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true
                && data_get($body, 'stream_options.include_usage') === true;
        });
    }

    public function testRequestSendsBearerTokenAuthorization(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testRequestSendsHttpRefererAndXOpenrouterTitleHeadersWhenConfigured(): void
    {
        config(['ai.providers.openrouter' => [
            ...config('ai.providers.openrouter'),
            'key' => 'test-key',
            'http_referer' => 'https://example.com',
            'x_title' => 'My App',
        ]]);

        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('HTTP-Referer', 'https://example.com')
            && $request->hasHeader('X-OpenRouter-Title', 'My App'));
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hypervel is great')]);

        $response = agent()->prompt('Tell me about Hypervel', provider: 'openrouter');

        $this->assertSame('Hypervel is great', $response->text);
        $this->assertSame('openrouter', $response->meta->provider);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'openrouter');

        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
    }

    public function testResponseUsageIncludesCacheAndReasoningTokens(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'prompt_tokens_details' => [
                    'cached_tokens' => 20,
                    'cache_write_tokens' => 30,
                ],
                'completion_tokens_details' => [
                    'reasoning_tokens' => 10,
                ],
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'openrouter');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
        $this->assertSame(20, $response->usage->cacheReadInputTokens);
        $this->assertSame(30, $response->usage->cacheWriteInputTokens);
        $this->assertSame(10, $response->usage->reasoningTokens);
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openrouter');

        $this->assertSame('Au', $response->structured['symbol']);
    }

    public function testWebSearchCitationsAreExtractedFromMessageAnnotations(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Paris is the capital of France.',
                    'annotations' => [
                        [
                            'type' => 'url_citation',
                            'url_citation' => [
                                'url' => 'https://example.com/paris',
                                'title' => 'Paris - Wikipedia',
                                'start_index' => 0,
                                'end_index' => 30,
                            ],
                        ],
                        [
                            'type' => 'url_citation',
                            'url_citation' => [
                                'url' => 'https://example.com/france',
                                'title' => 'France - Wikipedia',
                                'start_index' => 31,
                                'end_index' => 50,
                            ],
                        ],
                    ],
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])]);

        $response = agent()->prompt('What is the capital of France?', provider: 'openrouter');

        $this->assertCount(2, $response->meta->citations);
        $this->assertSame('https://example.com/paris', $response->meta->citations[0]->url);
        $this->assertSame('Paris - Wikipedia', $response->meta->citations[0]->title);
        $this->assertSame(0, $response->meta->citations[0]->startIndex);
        $this->assertSame(30, $response->meta->citations[0]->endIndex);
        $this->assertSame('https://example.com/france', $response->meta->citations[1]->url);
        $this->assertSame('France - Wikipedia', $response->meta->citations[1]->title);
        $this->assertSame(31, $response->meta->citations[1]->startIndex);
        $this->assertSame(50, $response->meta->citations[1]->endIndex);
    }

    public function testWebSearchCitationsOmitSpanIndicesWhenNotProvided(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Some answer.',
                    'annotations' => [[
                        'type' => 'url_citation',
                        'url_citation' => [
                            'url' => 'https://example.com/source',
                            'title' => 'Source',
                        ],
                    ]],
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
        ])]);

        $response = agent()->prompt('Question', provider: 'openrouter');

        $this->assertCount(1, $response->meta->citations);
        $this->assertSame('https://example.com/source', $response->meta->citations[0]->url);
        $this->assertNull($response->meta->citations[0]->startIndex);
        $this->assertNull($response->meta->citations[0]->endIndex);
    }

    public function testResponseWithNoAnnotationsHasEmptyCitationsCollection(): void
    {
        Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

        $response = agent()->prompt('Hi', provider: 'openrouter');

        $this->assertCount(0, $response->meta->citations);
    }
}
