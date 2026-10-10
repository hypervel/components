<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAiCompatible;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NestedStructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;

class OpenAiCompatibleTest extends TestCase
{
    /**
     * Configure the OpenAI-compatible provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai-compatible', [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
            'models' => ['text' => ['default' => 'local-model']],
        ]);
    }

    public function testThrowsWhenNoUrlIsConfigured(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("requires a 'url'");
        config(['ai.providers.openai-compatible' => [
            'driver' => 'openai-compatible',
            'key' => 'test-key',
            'models' => ['text' => ['default' => 'local-model']],
        ]]);

        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openai-compatible');
    }

    public function testThrowsWhenNoDefaultModelIsConfiguredAndNoneIsPassed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a default text model');
        config(['ai.providers.openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'key' => 'test-key',
        ]]);

        agent()->prompt('Hello', provider: 'openai-compatible');
    }

    public function testTextRequestsUseTheConfiguredBaseUrlAndPath(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('Hello from local model')]);

        $response = agent()->prompt('Hello', provider: 'openai-compatible');

        $this->assertSame('Hello from local model', $response->text);
        $this->assertSame('openai-compatible', $response->meta->provider);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://localhost:1234/v1/chat/completions');
    }

    public function testRequestSendsBearerTokenAuthorization(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testRequestOmitsAuthorizationHeaderWhenNoKeyIsConfigured(): void
    {
        config(['ai.providers.openai-compatible' => [
            ...config('ai.providers.openai-compatible'),
            'key' => null,
        ]]);

        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function testRequestWorksWhenTheKeyIsAbsentFromTheConfigEntirely(): void
    {
        config(['ai.providers.openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:1234/v1',
            'models' => ['text' => ['default' => 'local-model']],
        ]]);

        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('Hello')]);

        agent()->prompt('Hello', provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    }

    public function testStructuredOutputDefaultsToJsonSchemaResponseFormat(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai-compatible');

        Http::assertSent(function (Request $request): bool {
            $format = data_get(json_decode($request->body(), true), 'response_format');

            return $format['type'] === 'json_schema'
                && $format['json_schema']['strict'] === true;
        });
    }

    public function testStructuredOutputWithoutStrictAttributeSendsStrictFalseInResponseFormat(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('{"elements": []}')]);

        (new NestedStructuredAgent)->prompt('List elements.', provider: 'openai-compatible');

        Http::assertSent(function (Request $request): bool {
            $format = data_get(json_decode($request->body(), true), 'response_format');

            return $format['type'] === 'json_schema'
                && $format['json_schema']['strict'] === false;
        });
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai-compatible');

        $this->assertSame('Au', $response->structured['symbol']);
    }

    public function testStructuredResponseToleratesAMarkdownCodeFence(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse("```json\n" . '{"symbol": "Au"}' . "\n```")]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai-compatible');

        $this->assertSame('Au', $response->structured['symbol']);
    }

    public function testRequiredToolChoiceForcesTheModelToCallATool(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('42')]);

        (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('42')]);

        (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    #[DataProvider('toolCallResponses')]
    public function testNamedToolChoiceForcesASpecificFunction(bool $streamed, string $finishReason): void
    {
        $message = ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
            'index' => 0,
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'custom_named_tool', 'arguments' => '{}'],
        ]]];
        $data = [
            'model' => 'local-model',
            'choices' => [['index' => 0, $streamed ? 'delta' : 'message' => $message, 'finish_reason' => $finishReason]],
        ];

        Http::fake(['*' => Http::sequence([
            Http::response($streamed ? 'data: ' . json_encode($data) . "\n\ndata: [DONE]\n\n" : $data),
            $streamed ? $this->fakeOpenAiCompatibleStream() : $this->fakeOpenAiCompatibleResponse('Hello'),
        ])]);

        $agent = new ToolChoiceAgent(['tool' => 'custom_named_tool']);
        $response = $streamed
            ? $agent->stream('Give me a number', provider: 'openai-compatible')->each(fn (): true => true)
            : $agent->prompt('Give me a number', provider: 'openai-compatible');

        $this->assertSame('Hello', $response->text);
        Http::assertSentCount(2);

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === [
            'type' => 'function',
            'function' => ['name' => 'custom_named_tool'],
        ]);

        $body = json_decode(Http::recorded()[1][0]->body(), true);
        $toolResult = collect($body['messages'])->firstWhere('role', 'tool');
        $this->assertSame('call_1', $toolResult['tool_call_id']);
        $this->assertSame('ok', $toolResult['content']);
    }

    /**
     * Provide buffered and streamed tool-call finish reasons.
     */
    public static function toolCallResponses(): array
    {
        return [
            [false, 'stop'],
            [false, 'tool_calls'],
            [true, 'stop'],
            [true, 'tool_calls'],
        ];
    }

    public function testNoneToolChoicePreventsToolCalls(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('Sure')]);

        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'openai-compatible');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
    }

    public function testMaxTokensUsesTheMaxTokensFieldByDefault(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'openai-compatible');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'max_tokens') === 4096
                && ! array_key_exists('max_completion_tokens', $body);
        });
    }

    public function testResponseUsageIsParsedUsingTheOpenaiStandardShape(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'local-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'prompt_tokens_details' => ['cached_tokens' => 40],
                'completion_tokens_details' => ['reasoning_tokens' => 10],
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'openai-compatible');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
        $this->assertSame(40, $response->usage->cacheReadInputTokens);
        $this->assertSame(10, $response->usage->reasoningTokens);
    }

    public function testStreamingRejectsPrematureEof(): void
    {
        Http::fake(['*' => Http::response('data: ' . json_encode([
            'choices' => [['index' => 0, 'delta' => ['content' => 'Partial'], 'finish_reason' => null]],
        ]) . "\n\n")]);

        $error = null;

        try {
            agent()->stream('Hello', provider: 'openai-compatible')->each(fn (): true => true);
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('incomplete_stream', $error->type);
    }

    public function testStreamingOmitsStreamOptionsByDefault(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleStream()]);

        foreach (agent()->stream('Hello', provider: 'openai-compatible') as $event) {
            // drain the stream
        }

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true
                && ! array_key_exists('stream_options', $body);
        });
    }

    public function testStreamingSendsStreamOptionsWhenConfiguredOnTheInstance(): void
    {
        config(['ai.providers.openai-compatible' => [
            ...config('ai.providers.openai-compatible'),
            'stream_options' => ['include_usage' => true],
        ]]);

        Http::fake(['*' => $this->fakeOpenAiCompatibleStream()]);

        foreach (agent()->stream('Hello', provider: 'openai-compatible') as $event) {
            // drain the stream
        }

        Http::assertSent(fn (Request $request): bool => data_get(json_decode($request->body(), true), 'stream_options.include_usage') === true);
    }

    public function testStreamingSendsStreamOptionsSuppliedViaProviderOptions(): void
    {
        Http::fake(['*' => $this->fakeOpenAiCompatibleStream()]);

        $agent = new class implements Agent, HasProviderOptions {
            use Promptable;

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return ['stream_options' => ['include_usage' => true]];
            }
        };

        foreach ($agent->stream('Hello', provider: 'openai-compatible') as $event) {
            // drain the stream
        }

        Http::assertSent(fn (Request $request): bool => data_get(json_decode($request->body(), true), 'stream_options.include_usage') === true);
    }

    public function testCustomNamedInstancesUseTheirOwnConfiguredBaseUrlAndModel(): void
    {
        config(['ai.providers.lm-studio' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:4321/v1',
            'key' => 'lm-studio-key',
            'models' => ['text' => ['default' => 'lm-studio-model']],
        ]]);

        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('Hello from LM Studio')]);

        $response = agent()->prompt('Hello', provider: 'lm-studio');

        $this->assertSame('Hello from LM Studio', $response->text);
        $this->assertSame('lm-studio', $response->meta->provider);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:4321/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer lm-studio-key')
            && data_get(json_decode($request->body(), true), 'model') === 'lm-studio-model');
    }

    public function testNamedInstancesResolveProviderOptionsByTheirInstanceName(): void
    {
        config(['ai.providers.lm-studio' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:4321/v1',
            'key' => 'lm-studio-key',
            'models' => ['text' => ['default' => 'lm-studio-model']],
        ]]);

        config(['ai.providers.vllm' => [
            'driver' => 'openai-compatible',
            'url' => 'http://localhost:8000/v1',
            'key' => 'vllm-key',
            'models' => ['text' => ['default' => 'vllm-model']],
        ]]);

        Http::fake(['*' => $this->fakeOpenAiCompatibleResponse('Hello')]);

        $agent = new class implements Agent, HasProviderOptions {
            use Promptable;

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return match ($provider) {
                    'lm-studio' => ['top_k' => 40],
                    'vllm' => ['top_k' => 10],
                    default => [],
                };
            }
        };

        $agent->prompt('Hello', provider: 'lm-studio');
        $agent->prompt('Hello', provider: 'vllm');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:4321/v1/chat/completions'
            && data_get(json_decode($request->body(), true), 'top_k') === 40);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8000/v1/chat/completions'
            && data_get(json_decode($request->body(), true), 'top_k') === 10);
    }

    /**
     * Create a chat completion response.
     */
    protected function fakeOpenAiCompatibleResponse(string $content): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'local-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]);
    }

    /**
     * Create a completed chat stream.
     */
    protected function fakeOpenAiCompatibleStream(): PromiseInterface
    {
        $chunk = fn (array $delta, ?string $finish = null): string => 'data: ' . json_encode([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion.chunk',
            'model' => 'local-model',
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
        ]);

        $body = implode("\n\n", [
            $chunk(['role' => 'assistant', 'content' => 'Hello']),
            $chunk([], 'stop'),
            'data: [DONE]',
        ]) . "\n\n";

        return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
    }
}
