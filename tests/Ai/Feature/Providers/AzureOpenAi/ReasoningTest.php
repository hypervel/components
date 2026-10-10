<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\openAiReasoningItem;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ReasoningTest extends TestCase
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

    public function testPromptReadsReasoningItemsOffTheResponse(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'resp_azure_123',
            'status' => 'completed',
            'model' => 'o4-mini',
            'output' => [
                openAiReasoningItem('rs_1', 'Let me ', 'think...'),
                [
                    'type' => 'message',
                    'status' => 'completed',
                    'content' => [['type' => 'output_text', 'text' => 'Hello']],
                ],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'azure');

        $this->assertSame('Let me think...', $response->reasoning);
        $this->assertSame('Hello', $response->text);
    }
}
