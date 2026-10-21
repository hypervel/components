<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\MistralHelpers;
use Hypervel\Tests\Ai\TestCase;

class ToolCallLoopTest extends TestCase
{
    use MistralHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'mistral',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $hasAssistantWithToolCalls = false;
        $hasToolResult = false;

        foreach ($followUpBody['messages'] as $message) {
            if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
                $hasAssistantWithToolCalls = true;
            }

            if ($message['role'] === 'tool') {
                $hasToolResult = true;
            }
        }

        $this->assertTrue($hasAssistantWithToolCalls);
        $this->assertTrue($hasToolResult);
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

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'mistral',
        );

        $recorded = Http::recorded();

        $this->assertLessThanOrEqual(3, count($recorded));
    }

    public function testMultiStepToolLoopReturnsAccumulatedResponseShape(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new MultiStepToolAgent)->prompt(
            'Generate numbers',
            provider: 'mistral',
        );

        $this->assertSame('Done', (string) $response);
        $this->assertCount(5, $response->messages);
        $this->assertCount(3, $response->steps);
        $this->assertCount(2, $response->toolCalls);
        $this->assertCount(2, $response->toolResults);
        $this->assertSame(30, $response->usage->inputTokens);
        $this->assertSame(15, $response->usage->outputTokens);
    }

    public function testUnregisteredToolCallThrows(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('NonExistentTool', 'call_' . uniqid()),
            ]),
        ]);

        $this->expectException(NoSuchToolException::class);

        (new MultiStepToolAgent)->prompt(
            'Generate numbers',
            provider: 'mistral',
        );
    }

    public function testFollowUpRequestIncludesOriginalMessages(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'mistral',
        );

        $recorded = Http::recorded();

        $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

        $userMsg = collect($followUpBody['messages'])->firstWhere('role', 'user');

        $this->assertNotNull($userMsg);
    }

    public function testFollowUpResponseWithBlockContentIsFlattenedToText(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_' . uniqid()),
                $this->fakeTextResponse([
                    ['type' => 'text', 'text' => 'The number is 72019'],
                    ['type' => 'reference', 'reference_ids' => ['search_documents']],
                ]),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'mistral',
        );

        $this->assertSame('The number is 72019', $response->text);
    }
}
