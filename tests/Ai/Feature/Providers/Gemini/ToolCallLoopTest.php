<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NamedToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\TestCase;

/**
 * Get follow-up steps of the given type.
 */
function geminiFollowUpSteps(iterable $recorded, string $type): array
{
    return array_values(array_filter(
        $recorded[1][0]->data()['input'],
        fn (array $step): bool => ($step['type'] ?? null) === $type,
    ));
}

class ToolCallLoopTest extends TestCase
{
    use GeminiHelpers;

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'gemini',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);
        $this->assertCount(1, geminiFollowUpSteps($recorded, 'function_call'));
        $this->assertCount(1, geminiFollowUpSteps($recorded, 'function_result'));
    }

    public function testMaxStepsLimitsToolCallDepth(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'gemini',
        );

        $this->assertLessThanOrEqual(3, count(Http::recorded()));
    }

    public function testMultiStepToolLoopReturnsAccumulatedResponseShape(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new MultiStepToolAgent)->prompt(
            'Generate numbers',
            provider: 'gemini',
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
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse('NonExistentTool', 'call_missing'),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $this->expectException(NoSuchToolException::class);
        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');
    }

    public function testFunctionResultCarriesTheOriginatingCallId(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_abc123'),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $result = geminiFollowUpSteps($recorded, 'function_result')[0] ?? null;

        $this->assertNotNull($result, 'Follow-up should include a function_result step');
        $this->assertSame('FixedNumberGenerator', $result['name']);
        $this->assertSame('call_abc123', $result['call_id']);
        $this->assertSame('text', $result['result'][0]['type']);
    }

    public function testParallelFunctionCallsPreserveUniqueIds(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response($this->fakeInteraction([
                    $this->functionCallStep('FixedNumberGenerator', [], 'call_1'),
                    $this->functionCallStep('FixedNumberGenerator', [], 'call_2'),
                ])),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate two', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $ids = array_column(geminiFollowUpSteps($recorded, 'function_result'), 'call_id');

        $this->assertCount(2, $ids);
        $this->assertContains('call_1', $ids);
        $this->assertContains('call_2', $ids);
    }

    public function testToolDeclaringANameMethodRoutesTheFunctionCallBackToItself(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse('aliased_tool', 'call_named_1'),
                $this->fakeTextResponse('done'),
            ]),
        ]);

        (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $result = geminiFollowUpSteps($recorded, 'function_result')[0] ?? null;

        $this->assertNotNull($result, 'Follow-up should include a function_result for the declared tool name');
        $this->assertSame('aliased_tool', $result['name']);
    }

    public function testThoughtStepsAreReplayedVerbatimAcrossTheToolCallContinuation(): void
    {
        $thought = [
            'type' => 'thought',
            'summary' => [['type' => 'text', 'text' => 'Let me generate that...']],
            'signature' => 'sig_abc_123',
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response($this->fakeInteraction([
                    $thought,
                    $this->functionCallStep('FixedNumberGenerator', [], 'call_1'),
                ])),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        // Gemini rejects tool-call history whose thought steps were altered, so they
        // must survive the round trip byte for byte, signature included.
        $this->assertSame([$thought], geminiFollowUpSteps($recorded, 'thought'));
    }

    public function testTheUserInputEchoedByGeminiIsNotReplayedTwice(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response($this->fakeInteraction([
                    ['type' => 'user_input', 'content' => [['type' => 'text', 'text' => 'Generate']]],
                    $this->functionCallStep('FixedNumberGenerator', [], 'call_1'),
                ])),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $this->assertCount(1, geminiFollowUpSteps(Http::recorded(), 'user_input'));
    }
}
