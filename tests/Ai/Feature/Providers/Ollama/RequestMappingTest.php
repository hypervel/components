<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use Hypervel\Ai\ToolChoice;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\OllamaHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

use function Hypervel\Ai\agent;

class RequestMappingTest extends TestCase
{
    use OllamaHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.ollama.key', '');
    }

    public function testRequestIncludesModelAndMessages(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'ollama', model: 'llama3.1:8b');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'llama3.1:8b'
                && count($body['messages']) >= 1
                && collect($body['messages'])->contains(fn (array $m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
        });
    }

    public function testSystemInstructionsAreSentAsSystemMessage(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'helpful assistant');
        });
    }

    public function testTemperatureAndMaxTokensAreSentInOptionsObject(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'options.temperature') === 0.7
                && data_get($body, 'options.num_predict') === 4096;
        });
    }

    public function testTemperatureAndMaxTokensAreExcludedWhenNotSet(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('options', $body)
                || (! array_key_exists('temperature', $body['options'] ?? [])
                    && ! array_key_exists('num_predict', $body['options'] ?? []));
        });
    }

    public function testToolsAreIncludedInTheRequest(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return is_array($body['tools'])
                && $body['tools'] !== [];
        });
    }

    public function testUnsupportedToolChoiceIsRejected(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ollama does not support tool choice other than auto.');

        (new ToolChoiceAgent(ToolChoice::required))->prompt('Give me a number', provider: 'ollama');
    }

    public function testRequestWithoutToolsExcludesToolsField(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body);
        });
    }

    public function testStructuredOutputIncludesFormatField(): void
    {
        Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return array_key_exists('format', $body)
                && is_array($body['format']);
        });
    }

    public function testStructuredOutputAppendsJsonSchemaInstructionToSystemMessage(): void
    {
        Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMessage = collect($body['messages'])->firstWhere('role', 'system');

            return $systemMessage !== null
                && str_contains((string) $systemMessage['content'], 'You MUST respond EXCLUSIVELY with a JSON object that strictly adheres to the following schema')
                && str_contains((string) $systemMessage['content'], '"symbol"');
        });
    }

    public function testRequestWithoutSchemaExcludesFormatField(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('format', $body);
        });
    }

    public function testNonStreamingRequestSetsStreamToFalse(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'ollama');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === false;
        });
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hypervel is great')]);

        $response = agent()->prompt('Tell me about Hypervel', provider: 'ollama');

        $this->assertSame('Hypervel is great', $response->text);
        $this->assertSame('ollama', $response->meta->provider);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'llama3.1:8b',
            'message' => ['role' => 'assistant', 'content' => 'Hello'],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ])]);

        $response = agent()->prompt('Hello', provider: 'ollama');

        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
    }

    public function testResponseUsageReportsCachedPromptTokens(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'llama3.1:8b',
            'message' => ['role' => 'assistant', 'content' => 'Hello'],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 100,
            'prompt_eval_cached_count' => 80,
            'eval_count' => 5,
        ])]);

        $response = agent()->prompt('Hello', provider: 'ollama');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(80, $response->usage->cacheReadInputTokens);
        $this->assertSame(20, $response->usage->uncachedInputTokens());
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'ollama');

        $this->assertSame('Au', $response->structured['symbol']);
    }

    public function testRequestIsSentToTheChatEndpoint(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'ollama');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/chat'));
    }
}
