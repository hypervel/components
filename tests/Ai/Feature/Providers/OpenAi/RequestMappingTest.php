<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NestedStructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class RequestMappingTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testRequestIncludesModelAndInput(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('Hello')]);
        agent()->prompt('Hi there', provider: 'openai', model: 'gpt-5.4');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'gpt-5.4'
                && is_array($body['input'])
                && collect($body['input'])->contains(fn (array $message): bool => $message['role'] === 'user'
                    && collect($message['content'])->contains(fn (array $content): bool => ($content['text'] ?? '') === 'Hi there'));
        });
    }

    public function testSystemInstructionsAreSentAsSystemMessageInInput(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('Hello')]);
        (new AssistantAgent)->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMessage = collect($body['input'])->firstWhere('role', 'system');

            return $systemMessage !== null
                && str_contains((string) $systemMessage['content'], 'helpful assistant');
        });
    }

    public function testTemperatureMaxTokensAndTopPAreIncludedWhenSetViaAttributes(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('Hello')]);
        (new AttributeAgent)->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_output_tokens') === 4096
                && data_get($body, 'top_p') === 0.8;
        });
    }

    public function testTemperatureMaxTokensAndTopPAreExcludedWhenNotSet(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('Hello')]);
        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_output_tokens', $body)
                && ! array_key_exists('top_p', $body);
        });
    }

    public function testToolsIncludeToolChoiceAuto(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('42')]);
        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto' && is_array($body['tools']) && $body['tools'] !== [];
        });
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('Hello')]);
        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body) && ! array_key_exists('tool_choice', $body);
        });
    }

    public function testStructuredOutputIncludesJsonSchemaTextFormat(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('{"symbol": "Au"}')]);
        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $format = data_get(json_decode($request->body(), true), 'text.format');

            return $format['type'] === 'json_schema' && isset($format['name'], $format['schema']) && $format['strict'] === true;
        });
    }

    public function testStructuredAgentWithoutStrictAttributeSendsStrictFalseInTextFormat(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('{"elements": []}')]);
        (new NestedStructuredAgent)->prompt('List elements.', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $format = data_get(json_decode($request->body(), true), 'text.format');

            return $format['type'] === 'json_schema' && $format['strict'] === false;
        });
    }

    public function testRequestWithoutSchemaExcludesTextFormat(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('Hello')]);
        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('text', json_decode($request->body(), true)));
    }

    public function testRequestSendsBearerTokenAuthorization(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('Hello')]);
        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('Hypervel is great')]);
        $response = agent()->prompt('Tell me about Hypervel', provider: 'openai');

        $this->assertSame('Hypervel is great', $response->text);
        $this->assertSame('openai', $response->meta->provider);
    }

    public function testResponseTextIncludesEveryTextPartInOutputOrder(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_123',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [
                ['type' => 'message', 'status' => 'completed', 'content' => [
                    ['type' => 'output_text', 'text' => 'First'],
                    ['type' => 'output_text', 'text' => ' second'],
                ]],
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Reasoning']]],
                ['type' => 'message', 'status' => 'completed', 'content' => [
                    ['type' => 'refusal', 'refusal' => 'Cannot answer that part.'],
                    ['type' => 'output_text', 'text' => ' third'],
                ]],
            ],
        ])]);

        $this->assertSame('First second third', agent()->prompt('Hello', provider: 'openai')->text);
    }

    public function testIncompleteResponseDoesNotRunCompletedToolCalls(): void
    {
        Event::fake([InvokingTool::class]);
        Http::fake(['*' => Http::response([
            'id' => 'resp_123',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1',
                'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}',
            ]],
        ])]);

        $response = agent(tools: [new FixedNumberGenerator])->prompt('Hello', provider: 'openai');

        $this->assertSame(FinishReason::Length, $response->steps->first()->finishReason);
        $this->assertCount(0, $response->toolResults);
        Event::assertNotDispatched(InvokingTool::class);
        Http::assertSentCount(1);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_123', 'status' => 'completed', 'model' => 'gpt-5.4',
            'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Hello']]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);
        $response = agent()->prompt('Hello', provider: 'openai');

        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('{"symbol": "Au"}')]);
        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai');

        $this->assertSame('Au', $response->structured['symbol']);
    }

    public function testCitationsPreserveEveryAnnotationWithSpanIndices(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_123', 'status' => 'completed', 'model' => 'gpt-5.4',
            'output' => [['type' => 'message', 'status' => 'completed', 'content' => [[
                'type' => 'output_text', 'text' => 'Here are sources',
                'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://example.com/one', 'title' => 'Same Title', 'start_index' => 0, 'end_index' => 10],
                    ['type' => 'url_citation', 'url' => 'https://example.com/two', 'title' => 'Same Title', 'start_index' => 11, 'end_index' => 25],
                    ['type' => 'url_citation', 'url' => 'https://example.com/one', 'title' => 'Same Title', 'start_index' => 26, 'end_index' => 40],
                ],
            ]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);
        $response = agent()->prompt('Give me sources', provider: 'openai');

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
            'id' => 'resp_123', 'status' => 'completed', 'model' => 'gpt-5.4',
            'output' => [['type' => 'message', 'status' => 'completed', 'content' => [[
                'type' => 'output_text', 'text' => 'Sources',
                'annotations' => [['type' => 'url_citation', 'url' => 'https://example.com/one', 'title' => 'One']],
            ]]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);
        $response = agent()->prompt('Give me sources', provider: 'openai');

        $this->assertCount(1, $response->meta->citations);
        $this->assertNull($response->meta->citations[0]->startIndex);
        $this->assertNull($response->meta->citations[0]->endIndex);
    }

    public function testRequiredToolChoiceForcesTheModelToCallATool(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('42')]);
        (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'openai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('42')]);
        (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'openai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
    }

    public function testNamedToolChoiceForcesASpecificFunction(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('42')]);
        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'openai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === ['type' => 'function', 'name' => 'custom_named_tool']);
    }

    public function testNoneToolChoicePreventsToolCalls(): void
    {
        Http::fake(['*' => fakeOpenAiResponse('Sure')]);
        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'openai');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
    }

    public function testResponseUsageCapturesCacheWriteTokens(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_123', 'status' => 'completed', 'model' => 'gpt-5.6-luna',
            'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Hello']]]],
            'usage' => [
                'input_tokens' => 8817, 'output_tokens' => 120,
                'input_tokens_details' => ['cache_write_tokens' => 8814, 'cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 64],
            ],
        ])]);
        $response = agent()->prompt('Hello', provider: 'openai');

        $this->assertSame(8814, $response->usage->cacheWriteInputTokens);
        $this->assertSame(0, $response->usage->cacheReadInputTokens);
        $this->assertSame(8817, $response->usage->inputTokens);
        $this->assertSame(3, $response->usage->uncachedInputTokens());
        $this->assertSame(120, $response->usage->outputTokens);
        $this->assertSame(64, $response->usage->reasoningTokens);
    }

    public function testResponseUsageSeparatesCacheReadsFromCacheWrites(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_123', 'status' => 'completed', 'model' => 'gpt-5.6-luna',
            'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Hello']]]],
            'usage' => [
                'input_tokens' => 8817, 'output_tokens' => 120,
                'input_tokens_details' => ['cache_write_tokens' => 0, 'cached_tokens' => 8814],
                'output_tokens_details' => ['reasoning_tokens' => 64],
            ],
        ])]);
        $response = agent()->prompt('Hello', provider: 'openai');

        $this->assertSame(0, $response->usage->cacheWriteInputTokens);
        $this->assertSame(8814, $response->usage->cacheReadInputTokens);
        $this->assertSame(8817, $response->usage->inputTokens);
        $this->assertSame(3, $response->usage->uncachedInputTokens());
    }
}
