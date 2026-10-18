<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NestedStructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\MistralHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class RequestMappingTest extends TestCase
{
    use MistralHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testRequestIncludesModelAndMessages(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'mistral', model: 'mistral-large-latest');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'mistral-large-latest'
                && count($body['messages']) >= 1
                && collect($body['messages'])->contains(fn (array $m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
        });
    }

    public function testSystemInstructionsAreSentAsSystemMessage(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'helpful assistant');
        });
    }

    public function testTemperatureAndMaxTokensAreIncludedWhenSetViaAttributes(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_tokens') === 4096;
        });
    }

    public function testTemperatureAndMaxTokensAreExcludedWhenNotSet(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_tokens', $body);
        });
    }

    public function testToolsIncludeToolChoiceAuto(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && $body['tools'] !== [];
        });
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function testRequiredToolChoiceForcesTheModelToCallATool(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testNamedToolChoiceForcesASpecificFunction(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === [
            'type' => 'function',
            'function' => ['name' => 'custom_named_tool'],
        ]);
    }

    public function testNoneToolChoicePreventsToolCalls(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Sure')]);

        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
    }

    public function testStructuredOutputIncludesJsonSchemaResponseFormat(): void
    {
        Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'mistral');

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
        Http::fake(['*' => $this->fakeStructuredResponse('{"elements": []}')]);

        (new NestedStructuredAgent)->prompt('List elements.', provider: 'mistral');

        Http::assertSent(function (Request $request): bool {
            $format = data_get(json_decode($request->body(), true), 'response_format');

            return $format['type'] === 'json_schema'
                && $format['json_schema']['strict'] === false;
        });
    }

    public function testStreamingRequestIncludesStreamOptions(): void
    {
        Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

        $stream = agent()->stream('Hello', provider: 'mistral');

        foreach ($stream as $event);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true
                && data_get($body, 'stream_options.include_usage') === true;
        });
    }

    public function testRequestSendsBearerTokenAuthorization(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'mistral');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hypervel is great')]);

        $response = agent()->prompt('Tell me about Hypervel', provider: 'mistral');

        $this->assertSame('Hypervel is great', $response->text);
        $this->assertSame('mistral', $response->meta->provider);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'mistral-medium-latest',
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

        $response = agent()->prompt('Hello', provider: 'mistral');

        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
    }

    public function testResponseUsageReportsCachedPromptTokens(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'mistral-medium-latest',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello'],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1013,
                'completion_tokens' => 30,
                'prompt_tokens_details' => ['cached_tokens' => 1008],
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'mistral');

        $this->assertSame(1013, $response->usage->inputTokens);
        $this->assertSame(1008, $response->usage->cacheReadInputTokens);
        $this->assertSame(5, $response->usage->uncachedInputTokens());
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'mistral');

        $this->assertSame('Au', $response->structured['symbol']);
    }
}
