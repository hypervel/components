<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Hypervel\Ai\Responses\Data\ProviderToolCall;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\TestCase;

class ProviderToolCallsTest extends TestCase
{
    /**
     * Configure the xAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.xai.key', 'test-key');
    }

    public function testProviderToolOutputItemsLandOnTheStep(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 'resp_1',
                'status' => 'completed',
                'model' => 'grok-4',
                'output' => [
                    ['type' => 'code_execution_call', 'id' => 'ce_1', 'status' => 'completed', 'code' => 'print(1)'],
                    ['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => '1']]],
                ],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Run it', provider: 'xai');

        $this->assertSame([['ce_1', 'code_execution_call']], array_map(fn (ProviderToolCall $call): array => [$call->id, $call->type], $response->steps[0]->providerToolCalls));
    }
}
