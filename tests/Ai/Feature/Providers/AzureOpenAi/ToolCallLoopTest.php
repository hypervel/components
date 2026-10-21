<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeAzureResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolCallLoopTest extends TestCase
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

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
                $this->fakeUniqueAzureToolCallResponse(),
                fakeAzureResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'azure',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUpBody);

        $hasFunctionCallOutput = false;

        foreach ($followUpBody['input'] as $item) {
            if (($item['type'] ?? '') === 'function_call_output') {
                $hasFunctionCallOutput = true;
            }
        }

        $this->assertTrue($hasFunctionCallOutput);
    }

    public function testMaxStepsLimitsToolCallDepth(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
                $this->fakeUniqueAzureToolCallResponse(),
                $this->fakeUniqueAzureToolCallResponse(),
                $this->fakeUniqueAzureToolCallResponse(),
                fakeAzureResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'azure',
        );

        $recorded = Http::recorded();

        $this->assertLessThanOrEqual(3, count($recorded));
    }

    public function testMultiStepToolLoopReturnsAccumulatedResponseShape(): void
    {
        Http::fake([
            'my-resource.cognitiveservices.azure.com/*' => Http::sequence([
                $this->fakeUniqueAzureToolCallResponse(),
                $this->fakeUniqueAzureToolCallResponse(),
                fakeAzureResponse('Done'),
            ]),
        ]);

        $response = (new MultiStepToolAgent)->prompt(
            'Generate numbers',
            provider: 'azure',
        );

        $this->assertSame('Done', (string) $response);
        $this->assertCount(5, $response->messages);
        $this->assertCount(3, $response->steps);
        $this->assertCount(2, $response->toolCalls);
        $this->assertCount(2, $response->toolResults);
        $this->assertSame(21, $response->usage->inputTokens);
        $this->assertSame(11, $response->usage->outputTokens);
    }

    /**
     * Build a distinct tool-call response.
     */
    private function fakeUniqueAzureToolCallResponse(): PromiseInterface
    {
        $id = uniqid();

        return Http::response([
            'id' => 'resp_azure_tool_' . $id,
            'status' => 'completed',
            'model' => 'gpt-4o',
            'output' => [[
                'type' => 'function_call',
                'id' => 'fc_' . $id,
                'call_id' => 'call_' . $id,
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
