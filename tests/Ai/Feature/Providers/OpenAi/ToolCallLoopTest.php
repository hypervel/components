<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolChoiceAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiResponse;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class ToolCallLoopTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeUniqueOpenAiToolCallResponse(),
                fakeOpenAiResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'openai',
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
            'api.openai.com/*' => Http::sequence([
                fakeUniqueOpenAiToolCallResponse(),
                fakeUniqueOpenAiToolCallResponse(),
                fakeUniqueOpenAiToolCallResponse(),
                fakeOpenAiResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'openai',
        );

        $recorded = Http::recorded();

        $this->assertLessThanOrEqual(3, count($recorded));
    }

    public function testMultiStepToolLoopReturnsAccumulatedResponseShape(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeUniqueOpenAiToolCallResponse(),
                fakeUniqueOpenAiToolCallResponse(),
                fakeOpenAiResponse('Done'),
            ]),
        ]);

        $response = (new MultiStepToolAgent)->prompt(
            'Generate numbers',
            provider: 'openai',
        );

        $this->assertSame('Done', (string) $response);
        $this->assertCount(5, $response->messages);
        $this->assertCount(3, $response->steps);
        $this->assertCount(2, $response->toolCalls);
        $this->assertCount(2, $response->toolResults);
        $this->assertSame(21, $response->usage->inputTokens);
        $this->assertSame(11, $response->usage->outputTokens);
    }

    public function testAForcedToolChoiceIsReleasedOnTheFollowUpRequest(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiRandomNumberToolCallResponse(),
                fakeOpenAiResponse('The number is 7'),
            ]),
        ]);

        (new ToolChoiceAgent('required'))->prompt('Generate a random number', provider: 'openai');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);
        $this->assertSame('required', json_decode((string) $recorded[0][0]->body(), true)['tool_choice']);
        $this->assertSame('auto', json_decode((string) $recorded[1][0]->body(), true)['tool_choice']);
    }
}

function fakeOpenAiRandomNumberToolCallResponse(): PromiseInterface
{
    $id = uniqid();

    return Http::response([
        'id' => 'resp_tool_' . $id,
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_' . $id,
            'call_id' => 'call_' . $id,
            'name' => 'RandomNumberGenerator',
            'arguments' => '{"min":1,"max":10}',
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}

function fakeUniqueOpenAiToolCallResponse(): PromiseInterface
{
    $id = uniqid();

    return Http::response([
        'id' => 'resp_tool_' . $id,
        'status' => 'completed',
        'model' => 'gpt-5.4',
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
