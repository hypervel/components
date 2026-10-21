<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\CohereHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;

class RequestMappingTest extends TestCase
{
    use CohereHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    public function testRequestIsSentToTheChatEndpointWithModelAndMessages(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'cohere', model: 'command-r7b-12-2024');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api.cohere.com/v2/chat'
                && $body['model'] === 'command-r7b-12-2024'
                && collect($body['messages'])->contains(fn (array $message): bool => $message['role'] === 'user' && $message['content'] === 'Hi there');
        });
    }

    public function testDefaultTextModelIsUsedWhenNoneIsGiven(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'cohere');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'command-a-03-2025');
    }

    public function testSystemInstructionsAreSentAsSystemMessage(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $systemMsg = collect(json_decode($request->body(), true)['messages'])->firstWhere('role', 'system');

            return $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'helpful assistant');
        });
    }

    public function testGenerationOptionsAreMappedWhenSetViaAttributes(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_tokens') === 4096
                && data_get($body, 'p') === 0.8
                && ! array_key_exists('top_p', $body);
        });
    }

    public function testGenerationOptionsAreExcludedWhenNotSet(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_tokens', $body)
                && ! array_key_exists('p', $body);
        });
    }

    public function testToolsAreSentWithoutAToolChoiceByDefault(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tool_choice', $body)
                && $body['tools'][0]['type'] === 'function'
                && $body['tools'][0]['function']['name'] === 'RandomNumberGenerator';
        });
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function testRequiredToolChoiceIsSentInUppercase(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'cohere');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'REQUIRED');
    }

    public function testRequiredToolChoiceCanBeSetViaAttribute(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'cohere');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'REQUIRED');
    }

    public function testNoneToolChoiceIsSentInUppercase(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Sure')]);

        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'cohere');

        Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'NONE');
    }

    public function testNamedToolChoiceOnlyOffersThatToolAndRequiresACall(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('42')]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'REQUIRED'
                && count($body['tools']) === 1
                && $body['tools'][0]['function']['name'] === 'custom_named_tool';
        });
    }

    public function testNamedToolChoiceForAnUnknownToolThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool choice [missing_tool] does not match any of the available tools.');
        Http::fake();

        (new ToolChoiceAgent(['tool' => 'missing_tool']))->prompt('Give me a number', provider: 'cohere');
    }

    public function testStructuredOutputSendsAJsonObjectResponseFormat(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('{"symbol":"Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $format = data_get(json_decode($request->body(), true), 'response_format');

            return $format['type'] === 'json_object'
                && $format['json_schema']['type'] === 'object'
                && $format['json_schema']['required'] === ['symbol']
                && ! array_key_exists('name', $format['json_schema']);
        });
    }

    public function testSchemaCombinedWithToolsOmitsResponseFormatButKeepsSchemaInstructions(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('{"number": 42}')]);

        agent(
            tools: [new RandomNumberGenerator],
            schema: fn (JsonSchema $schema): array => ['number' => $schema->integer()->required()],
        )->prompt('Give me a number', provider: 'cohere');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

            return ! array_key_exists('response_format', $body)
                && is_array($body['tools'])
                && $systemMsg !== null
                && str_contains((string) $systemMsg['content'], 'JSON object that strictly adheres');
        });
    }

    public function testStreamingRequestEnablesStreamingWithoutStreamOptions(): void
    {
        Http::fake(['*' => $this->fakeStreamResponse($this->streamTextEvents('Hi'))]);

        $this->collectStreamEvents();

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true
                && ! array_key_exists('stream_options', $body);
        });
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Your name is Sam.')]);

        $response = agent()->prompt('What is my name?', provider: 'cohere', model: 'command-a-03-2025');

        $this->assertSame('Your name is Sam.', $response->text);
        $this->assertSame('cohere', $response->meta->provider);
        $this->assertSame('command-a-03-2025', $response->meta->model);
    }

    public function testResponseTextJoinsMultipleTextBlocks(): void
    {
        Http::fake(['*' => $this->fakeTextResponse([
            ['type' => 'text', 'text' => 'Hello'],
            ['type' => 'text', 'text' => ' world'],
        ])]);

        $this->assertSame('Hello world', agent()->prompt('Hi', provider: 'cohere')->text);
    }

    public function testResponseUsageReportsTokenCountsAndCachedTokens(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        $response = agent()->prompt('Hello', provider: 'cohere');

        $this->assertSame(557, $response->usage->inputTokens);
        $this->assertSame(8, $response->usage->outputTokens);
        $this->assertSame(480, $response->usage->cacheReadInputTokens);
    }

    public function testResponseUsageLeavesCachedTokensNullWhenAbsent(): void
    {
        Http::fake(['*' => Http::response([
            'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            'finish_reason' => 'COMPLETE',
            'usage' => ['tokens' => ['input_tokens' => 557, 'output_tokens' => 8]],
        ])]);

        $this->assertNull(agent()->prompt('Hello', provider: 'cohere')->usage->cacheReadInputTokens);
    }

    #[DataProvider('finishReasons')]
    public function testFinishReasonsAreMapped(string $cohereReason, FinishReason $expected): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello', $cohereReason)]);

        $response = agent()->prompt('Hello', provider: 'cohere');

        $this->assertSame($expected, $response->steps->last()->finishReason);
    }

    /**
     * Provide provider finish reasons and their normalized values.
     */
    public static function finishReasons(): array
    {
        return [
            ['COMPLETE', FinishReason::Stop],
            ['STOP_SEQUENCE', FinishReason::Stop],
            ['MAX_TOKENS', FinishReason::Length],
            ['ERROR', FinishReason::Error],
            ['TIMEOUT', FinishReason::Error],
            ['SOMETHING_NEW', FinishReason::Unknown],
        ];
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('{"symbol":"Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'cohere');

        $this->assertSame('Au', $response->structured['symbol']);
    }
}
