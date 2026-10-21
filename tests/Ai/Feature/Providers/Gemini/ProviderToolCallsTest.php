<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\Responses\Data\ProviderToolCall;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\TestCase;

class ProviderToolCallsTest extends TestCase
{
    use GeminiHelpers;

    public function testCodeExecutionStepsLandOnTheStep(): void
    {
        $call = [
            'type' => 'code_execution_call',
            'id' => 'ce_1',
            'content' => [['type' => 'text', 'text' => 'print(1)']],
        ];

        $result = [
            'type' => 'code_execution_result',
            'id' => 'ce_1',
            'content' => [['type' => 'text', 'text' => '1']],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction([
                $call,
                $result,
                $this->modelOutput('The answer is 1.'),
            ])),
        ]);

        $response = (new AssistantAgent)->prompt('Run it', provider: 'gemini');

        $this->assertSame([$call, $result], array_map(fn (ProviderToolCall $call): array => $call->data, $response->steps[0]->providerToolCalls));
        $this->assertSame('code_execution', $response->steps[0]->providerToolCalls[0]->type);
        $this->assertSame('code_execution', $response->steps[0]->providerToolCalls[1]->type);
        $this->assertSame('The answer is 1.', $response->text);
    }

    public function testGoogleSearchStepsAreReportedAsProviderToolCalls(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction([
                ['type' => 'google_search_call', 'content' => [['type' => 'text', 'text' => 'euro 2024 winner']]],
                ['type' => 'google_search_result', 'content' => [['type' => 'text', 'text' => 'Spain won.']]],
                $this->modelOutput('Spain won Euro 2024.'),
            ])),
        ]);

        $response = (new AssistantAgent)->prompt('Who won?', provider: 'gemini');

        $this->assertSame(['google_search', 'google_search'], array_map(fn (ProviderToolCall $call): string => $call->type, $response->steps[0]->providerToolCalls));
    }
}
