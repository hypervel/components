<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NamedToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\StoredToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
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

    public function testAStoredInteractionIsContinuedWithPreviousInteractionId(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new StoredToolAgent)->prompt('Generate a random number', provider: 'gemini');

        $recorded = Http::recorded();

        $followUp = $recorded[1][0]->data();

        $this->assertArrayNotHasKey('previous_interaction_id', $recorded[0][0]->data());
        $this->assertSame('int_123', $followUp['previous_interaction_id']);
        $this->assertCount(1, $followUp['input']);
        $this->assertSame('function_result', $followUp['input'][0]['type']);
        $this->assertSame('FixedNumberGenerator', $followUp['input'][0]['name']);
        $this->assertNotEmpty($followUp['system_instruction']);
        $this->assertNotEmpty($followUp['tools']);
    }

    public function testEachStepOfAStoredToolLoopContinuesFromTheInteractionBeforeIt(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response(array_merge($this->fakeInteraction([$this->functionCallStep('FixedNumberGenerator', [], 'call_1')]), ['id' => 'int_1'])),
                Http::response(array_merge($this->fakeInteraction([$this->functionCallStep('FixedNumberGenerator', [], 'call_2')]), ['id' => 'int_2'])),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new StoredToolAgent)->prompt('Generate numbers', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(3, $recorded);
        $this->assertSame('int_1', $recorded[1][0]->data()['previous_interaction_id']);
        $this->assertSame('call_1', $recorded[1][0]->data()['input'][0]['call_id']);
        $this->assertSame('int_2', $recorded[2][0]->data()['previous_interaction_id']);
        $this->assertSame('call_2', $recorded[2][0]->data()['input'][0]['call_id']);
    }

    public function testAStoredInteractionContinuesFromTheCallersOwnPreviousInteractionOnlyOnTheFirstStep(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new StoredToolAgent(['previous_interaction_id' => 'int_earlier']))->prompt('Generate a random number', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertSame('int_earlier', $recorded[0][0]->data()['previous_interaction_id']);
        $this->assertSame('int_123', $recorded[1][0]->data()['previous_interaction_id']);
    }

    public function testAContinuationCarriesTheSameSchemaAndGenerationConfigAsTheFirstRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeStructuredResponse(['number' => 72019]),
            ]),
        ]);

        $agent = new class implements Agent, HasProviderOptions, HasStructuredOutput, HasTools {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant that generates numbers.';
            }

            /**
             * Get the agent's tools.
             */
            public function tools(): iterable
            {
                return [new FixedNumberGenerator];
            }

            /**
             * Get the agent's structured output schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return ['number' => $schema->integer()->required()];
            }

            /**
             * Get the provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return ['store' => true, 'thinking_level' => 'high'];
            }
        };

        $agent->prompt('Generate a random number', provider: 'gemini');

        [$first, $followUp] = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();

        $this->assertSame('int_123', $followUp['previous_interaction_id']);
        $this->assertSame($first['response_format'], $followUp['response_format']);
        $this->assertSame('application/json', $followUp['response_format']['mime_type']);
        $this->assertSame($first['generation_config'], $followUp['generation_config']);
        $this->assertSame('high', $followUp['generation_config']['thinking_level']);
    }
}
