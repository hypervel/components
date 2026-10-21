<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StructuredWithThinkingAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiToolCallResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ProviderOptionsTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testProviderOptionsAreIncludedInOpenaiRequestBody(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('Hello'),
        ]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'reasoning.effort') === 'high'
                && data_get($body, 'frequency_penalty') === 0.5
                && data_get($body, 'presence_penalty') === 0.3;
        });
    }

    public function testRequestBodyDoesNotContainProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('Hello'),
        ]);

        agent()->prompt('Hello', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('reasoning', $body)
                && ! array_key_exists('frequency_penalty', $body)
                && ! array_key_exists('presence_penalty', $body);
        });
    }

    public function testProviderOptionsArePersistedInToolCallFollowUpRequests(): void
    {
        Http::fake([
            '*' => Http::sequence([
                fakeOpenAiToolCallResponse(),
                fakeOpenAiResponse('The number is 72019'),
            ]),
        ]);

        (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'openai');

        $requests = Http::recorded();

        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode((string) $requests[1][0]->body(), true);

        $this->assertSame('high', data_get($followUpBody, 'reasoning.effort'));
        $this->assertSame(0.5, data_get($followUpBody, 'frequency_penalty'));
        $this->assertArrayHasKey('previous_response_id', $followUpBody);
    }

    public function testStructuredOutputKeepsItsFormatWhenProviderOptionsSetText(): void
    {
        Http::fake([
            '*' => fakeOpenAiResponse('{"name":"Taylor","age":30}'),
        ]);

        (new StructuredWithThinkingAgent)->prompt('Tell me about Taylor', provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'text.format.type') === 'json_schema'
                && data_get($body, 'text.verbosity') === 'low'
                && data_get($body, 'reasoning.effort') === 'low';
        });
    }
}
