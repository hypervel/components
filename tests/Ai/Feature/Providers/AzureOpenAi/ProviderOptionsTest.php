<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeAzureResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ProviderOptionsTest extends TestCase
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

    public function testProviderOptionsAreIncludedInAzureRequestBody(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('Hello'),
        ]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return data_get($body, 'frequency_penalty') === 0.5
                && data_get($body, 'presence_penalty') === 0.3;
        });
    }

    public function testRequestBodyDoesNotContainProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        Http::fake([
            '*' => fakeAzureResponse('Hello'),
        ]);

        agent()->prompt('Hello', provider: 'azure');

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
                $this->fakeAzureProviderOptionsToolCallResponse(),
                fakeAzureResponse('The number is 72019'),
            ]),
        ]);

        (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'azure');

        $requests = Http::recorded();

        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode((string) $requests[1][0]->body(), true);

        $this->assertSame(0.5, data_get($followUpBody, 'frequency_penalty'));
    }

    /**
     * Build a tool-call response.
     */
    private function fakeAzureProviderOptionsToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'resp_azure_tool_123',
            'status' => 'completed',
            'model' => 'gpt-4o',
            'output' => [[
                'type' => 'function_call',
                'id' => 'fc_123',
                'call_id' => 'call_123',
                'name' => 'FixedNumberGenerator',
                'arguments' => '{}',
                'status' => 'completed',
            ]],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ]);
    }
}
