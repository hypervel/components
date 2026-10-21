<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeAzureResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class RequestMappingTest extends TestCase
{
    /**
     * Configure the Azure OpenAI deployment.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('ai.providers.azure', [
            ...$config->get('ai.providers.azure'),
            'key' => 'test-key',
            'url' => 'https://my-resource.cognitiveservices.azure.com',
            'deployment' => 'gpt-4o',
        ]);
    }

    public function testRequestIncludesModelAndInput(): void
    {
        Http::fake(['*' => fakeAzureResponse('Hello')]);

        agent()->prompt('Hi there', provider: 'azure', model: 'gpt-4o');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'gpt-4o'
                && is_array($body['input'])
                && collect($body['input'])->contains(fn (array $message): bool => $message['role'] === 'user'
                    && collect($message['content'])->contains(fn (array $content): bool => ($content['text'] ?? '') === 'Hi there'));
        });
    }

    public function testSystemInstructionsAreSentAsSystemMessageInInput(): void
    {
        Http::fake(['*' => fakeAzureResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $systemMessage = collect($body['input'])->firstWhere('role', 'system');

            return $systemMessage !== null
                && str_contains((string) $systemMessage['content'], 'helpful assistant');
        });
    }

    public function testTemperatureAndMaxTokensAreIncludedWhenSetViaAttributes(): void
    {
        Http::fake(['*' => fakeAzureResponse('Hello')]);

        (new AttributeAgent)->prompt('Hello', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'temperature') === 0.7
                && data_get($body, 'max_output_tokens') === 4096;
        });
    }

    public function testTemperatureAndMaxTokensAreExcludedWhenNotSet(): void
    {
        Http::fake(['*' => fakeAzureResponse('Hello')]);

        agent()->prompt('Hello', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('max_output_tokens', $body);
        });
    }

    public function testToolsIncludeToolChoiceAuto(): void
    {
        Http::fake(['*' => fakeAzureResponse('42')]);

        agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['tool_choice'] === 'auto'
                && is_array($body['tools'])
                && $body['tools'] !== [];
        });
    }

    public function testRequestWithoutToolsExcludesToolFields(): void
    {
        Http::fake(['*' => fakeAzureResponse('Hello')]);

        agent()->prompt('Hello', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('tools', $body)
                && ! array_key_exists('tool_choice', $body);
        });
    }

    public function testStructuredOutputIncludesJsonSchemaTextFormat(): void
    {
        Http::fake(['*' => fakeAzureResponse('{"symbol": "Au"}')]);

        (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $format = data_get($body, 'text.format');

            return ($format['type'] ?? '') === 'json_schema'
                && isset($format['name'], $format['schema'])
                && $format['strict'] === true;
        });
    }

    public function testRequestWithoutSchemaExcludesTextFormat(): void
    {
        Http::fake(['*' => fakeAzureResponse('Hello')]);

        agent()->prompt('Hello', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('text', $body);
        });
    }

    public function testStreamingRequestIncludesStreamFlag(): void
    {
        Http::fake(['*' => Http::response(
            body: "data: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_1\",\"model\":\"gpt-4o\",\"status\":\"in_progress\",\"output\":[]}}\n\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Hi\",\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0}\n\ndata: {\"type\":\"response.output_text.done\",\"text\":\"Hi\",\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0}\n\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\",\"model\":\"gpt-4o\",\"status\":\"completed\",\"output\":[{\"type\":\"message\",\"status\":\"completed\",\"role\":\"assistant\",\"content\":[{\"type\":\"output_text\",\"text\":\"Hi\"}]}],\"usage\":{\"input_tokens\":1,\"output_tokens\":1,\"input_tokens_details\":{\"cached_tokens\":0},\"output_tokens_details\":{\"reasoning_tokens\":0}}}}\n\n",
        )]);

        $stream = agent()->stream('Hello', provider: 'azure');

        foreach ($stream as $event);

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true;
        });
    }

    public function testRequestSendsApiKeyHeaderAuthentication(): void
    {
        Http::fake(['*' => fakeAzureResponse('Hello')]);

        agent()->prompt('Hello', provider: 'azure');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('api-key', 'test-key'));
    }

    public function testRequestDoesNotIncludeApiVersionQueryParameter(): void
    {
        Http::fake(['*' => fakeAzureResponse('Hello')]);

        agent()->prompt('Hello', provider: 'azure');

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'api-version'));
    }

    public function testResponseTextIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeAzureResponse('Hypervel is great')]);

        $response = agent()->prompt('Tell me about Hypervel', provider: 'azure');

        $this->assertSame('Hypervel is great', $response->text);
        $this->assertSame('azure', $response->meta->provider);
    }

    public function testResponseUsageIsCorrectlyParsed(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_azure_123',
            'status' => 'completed',
            'model' => 'gpt-4o',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'Hello']],
            ]],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ])]);

        $response = agent()->prompt('Hello', provider: 'azure');

        $this->assertSame(10, $response->usage->inputTokens);
        $this->assertSame(5, $response->usage->outputTokens);
    }

    public function testStructuredResponseIsCorrectlyParsed(): void
    {
        Http::fake(['*' => fakeAzureResponse('{"symbol": "Au"}')]);

        $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'azure');

        $this->assertSame('Au', $response->structured['symbol']);
    }
}
