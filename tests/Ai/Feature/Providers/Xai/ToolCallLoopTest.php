<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\XaiHelpers;
use Hypervel\Tests\Ai\TestCase;

class ToolCallLoopTest extends TestCase
{
    use XaiHelpers;

    /**
     * Configure the xAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.xai.key', 'test-key');
    }

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'xai',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUpBody);

        $hasToolOutput = false;

        foreach ($followUpBody['input'] as $item) {
            if (($item['type'] ?? '') === 'function_call_output') {
                $hasToolOutput = true;
            }
        }

        $this->assertTrue($hasToolOutput, 'Follow-up request should include function_call_output');
    }

    public function testMaxStepsLimitsToolCallDepth(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'xai',
        );

        $recorded = Http::recorded();

        $this->assertLessThanOrEqual(3, count($recorded));
    }

    public function testFollowUpRequestPreservesTools(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'xai',
        );

        $recorded = Http::recorded();

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $this->assertArrayHasKey('tools', $followUpBody);
        $this->assertNotEmpty($followUpBody['tools']);
    }
}
