<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\JsonSchema\JsonSchema;
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
use function Hypervel\Tests\Ai\Fixtures\fakeGroqResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class RequestMappingTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.groq.key', 'test-key');
    }

    public function testRequestIncludesModelAndMessages(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'groq', model: 'llama-3.3-70b-versatile');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'llama-3.3-70b-versatile'
                && count($body['messages']) >= 1
                && collect($body['messages'])->contains(fn (array $m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
        });
    }

    public function testSystemInstructionsAreSentAsSystemMessage(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'helpful assistant');
        });
    }

    public function testTemperatureAndMaxTokensAreIncludedWhenSetViaAttributes(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_completion_tokens') === 4096;
        });
    }

    public function testTemperatureAndMaxTokensAreExcludedWhenNotSet(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hello')]);

        agent()->prompt('Hello', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_completion_tokens', $body);
        });
    }

    public function testToolsIncludeToolChoiceAuto(): void
    {
        Http::fake(['*' => fakeGroqResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && $body['tools'] !== [];
        });
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hello')]);

        agent()->prompt('Hello', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function testRequiredToolChoiceForcesTheModelToCallATool(): void
    {
        Http::fake(['*' => fakeGroqResponse('42')]);

        (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'groq');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake(['*' => fakeGroqResponse('42')]);

        (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'groq');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testNamedToolChoiceForcesASpecificFunction(): void
    {
        Http::fake(['*' => fakeGroqResponse('42')]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'groq');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === [
            'type' => 'function',
            'function' => ['name' => 'custom_named_tool'],
        ]);
    }

    public function testNoneToolChoicePreventsToolCalls(): void
    {
        Http::fake(['*' => fakeGroqResponse('Sure')]);

        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'groq');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
    }

    public function testStructuredOutputIncludesJsonSchemaResponseFormat(): void
    {
        Http::fake(['*' => fakeGroqResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'groq');

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
        Http::fake(['*' => fakeGroqResponse('{"elements": []}')]);

        (new NestedStructuredAgent)->prompt('List elements.', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $format = data_get(json_decode($request->body(), true), 'response_format');

            return $format['type'] === 'json_schema'
                && $format['json_schema']['strict'] === false;
        });
    }

    public function testRequestWithoutSchemaExcludesResponseFormat(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hello')]);

        agent()->prompt('Hello', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('response_format', $body);
        });
    }

    public function testSchemaCombinedWithToolsOmitsResponseFormatButKeepsSchemaInstructions(): void
    {
        Http::fake(['*' => fakeGroqResponse('{"number": 42}')]);

        agent(
            tools: [new RandomNumberGenerator],
            schema: fn (JsonSchema $s): array => ['number' => $s->integer()->required()],
        )->prompt('Give me a number', provider: 'groq');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return ! array_key_exists('response_format', $body)
                && is_array($body['tools'])
                && $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'JSON object that strictly adheres');
        });
    }

    public function testStreamingRequestIncludesStreamOptions(): void
    {
        Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

        $stream = agent()->stream('Hello', provider: 'groq');

        // Consume the stream
        foreach ($stream as $event);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true
                && data_get($body, 'stream_options.include_usage') === true;
        });
    }

    public function testRequestSendsBearerTokenAuthorization(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hello')]);

        agent()->prompt('Hello', provider: 'groq');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeGroqResponse('Hypervel is great')]);

        $response = agent()->prompt('Tell me about Hypervel', provider: 'groq');

        $this->assertSame('Hypervel is great', $response->text);
        $this->assertSame('groq', $response->meta->provider);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
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

        $response = agent()->prompt('Hello', provider: 'groq');

        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
    }

    public function testResponseUsageIncludesReasoningTokens(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-r1-1',
            'object' => 'chat.completion',
            'model' => 'deepseek-r1-distill-llama-70b',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'The answer is 4.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
                'completion_tokens_details' => [
                    'reasoning_tokens' => 20,
                ],
            ],
        ])]);

        $response = agent()->prompt('What is 2+2?', provider: 'groq', model: 'deepseek-r1-distill-llama-70b');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(50, $response->usage->outputTokens);
        $this->assertSame(20, $response->usage->reasoningTokens);
    }

    public function testResponseUsageIncludesCachedPromptTokens(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-cache-1',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 4641,
                'completion_tokens' => 1817,
                'total_tokens' => 6458,
                'prompt_tokens_details' => [
                    'cached_tokens' => 4608,
                ],
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'groq');

        $this->assertSame(4641, $response->usage->inputTokens);
        $this->assertSame(1817, $response->usage->outputTokens);
        $this->assertSame(4608, $response->usage->cacheReadInputTokens);
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeGroqResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'groq');

        $this->assertSame('Au', $response->structured['symbol']);
    }
}
