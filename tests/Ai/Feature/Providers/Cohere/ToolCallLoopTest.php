<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\CohereHelpers;
use Hypervel\Tests\Ai\TestCase;

class ToolCallLoopTest extends TestCase
{
    use CohereHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_1'),
                $this->fakeTextResponse('The random number generated is 72019.'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'cohere');

        $this->assertCount(2, Http::recorded());
        $this->assertSame('The random number generated is 72019.', $response->text);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('FixedNumberGenerator', $response->toolCalls->first()->name);
        $this->assertSame('72019', $response->toolResults->first()->result);
        $this->assertSame(FinishReason::ToolCalls, $response->steps->first()->finishReason);
    }

    public function testToolCallArgumentsAreDecoded(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('RandomNumberGenerator', 'call_1', '{"min":1,"max":10}'),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent)->prompt('Generate a random number', provider: 'cohere');

        $this->assertSame(['min' => 1, 'max' => 10], $response->toolCalls->first()->arguments);
    }

    public function testEmptyToolCallArgumentsDecodeToAnEmptyArray(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_1', ''),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'cohere');

        $this->assertSame([], $response->toolCalls->first()->arguments);
    }

    public function testMultiStepToolLoopAccumulatesUsage(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_1'),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new MultiStepToolAgent)->prompt('Generate a number', provider: 'cohere');

        $this->assertCount(2, $response->steps);
        $this->assertSame(1439 + 557, $response->usage->inputTokens);
        $this->assertSame(47 + 8, $response->usage->outputTokens);
    }

    public function testUnregisteredToolCallThrows(): void
    {
        $this->expectException(NoSuchToolException::class);
        Http::fake(['*' => $this->fakeToolCallResponse('UnknownTool', 'call_1')]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'cohere');
    }
}
