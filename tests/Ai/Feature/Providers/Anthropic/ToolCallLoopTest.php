<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Closure;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\StepResult;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\PendingStep;
use Hypervel\Ai\Promptable;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use stdClass;

use function Hypervel\Ai\agent;

class ToolCallLoopTest extends TestCase
{
    use AnthropicHelpers;

    public function testSyntheticStructuredToolCallsAreNotReplayedAlongsideRealTools(): void
    {
        config(['ai.providers.anthropic.use_native_structured_output' => false]);
        Http::fake(['api.anthropic.com/*' => Http::sequence([
            Http::response([
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'Choose a number.', 'signature' => 'signed'],
                    ['type' => 'tool_use', 'id' => 'synthetic', 'name' => 'output_structured_data', 'input' => ['number' => 0]],
                    ['type' => 'tool_use', 'id' => 'real', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                ],
                'stop_reason' => 'tool_use',
            ]),
            $this->fakeSyntheticStructuredResponse(['number' => 72019]),
        ])]);

        $response = agent(tools: [new FixedNumberGenerator], schema: fn (): array => ['number' => (new JsonSchemaTypeFactory)->integer()])
            ->prompt('Generate a number', provider: 'anthropic');

        $messages = Http::recorded()[1][0]->data()['messages'];
        $assistant = collect($messages)->firstWhere('role', 'assistant');
        $this->assertSame(['thinking', 'tool_use'], array_column($assistant['content'], 'type'));
        $this->assertSame('signed', $assistant['content'][0]['signature']);
        $this->assertSame('real', $assistant['content'][1]['id']);
        $this->assertSame('real', $messages[array_key_last($messages)]['content'][0]['tool_use_id']);
        $this->assertSame(72019, $response['number']);
    }

    public function testToolCallsTriggerFollowUpRequest(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = $recorded[1][0]->data();

        $hasAssistantWithToolUse = false;
        $hasToolResult = false;

        foreach ($followUpBody['messages'] as $message) {
            if ($message['role'] === 'assistant') {
                foreach ($message['content'] as $block) {
                    if (($block['type'] ?? '') === 'tool_use') {
                        $hasAssistantWithToolUse = true;
                    }
                }
            }

            if ($message['role'] === 'user') {
                foreach ($message['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $hasToolResult = true;
                    }
                }
            }
        }

        $this->assertTrue($hasAssistantWithToolUse, 'Follow-up request should include assistant message with tool_use block');
        $this->assertTrue($hasToolResult, 'Follow-up request should include user message with tool_result block');
    }

    public function testToolCallsRunWhenTheMessageEndsWithEndTurn(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_123',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        ['type' => 'text', 'text' => 'Let me generate a number.'],
                        ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => new stdClass],
                    ],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $this->assertCount(2, Http::recorded());
        $this->assertSame('The number is 72019', $response->text);
    }

    #[TestWith([null])]
    #[TestWith(['instructions'])]
    #[TestWith(['messages'])]
    public function testTheCodeExecutionContainerIsReusedByTheFollowUpRequest(?string $edit): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_123',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => new stdClass],
                    ],
                    'container' => ['id' => 'container_123', 'expires_at' => '2026-11-08T11:00:00Z'],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->withMiddleware([
            fn (PendingStep $step, Closure $next): StepResult => $next(match ($edit) {
                'instructions' => $step->isFirstStep() ? $step : $step->withInstructions('Wrap up.'),
                'messages' => $step->isFirstStep() ? $step->withMessages([new UserMessage('Summarized')]) : $step,
                default => $step,
            }),
        ])->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);
        $this->assertArrayNotHasKey('container', $recorded[0][0]->data());
        $this->assertSame('container_123', $recorded[1][0]->data()['container']);

        if ($edit === 'instructions') {
            $this->assertSame('Wrap up.', $recorded[1][0]->data()['system']);
        }
    }

    public function testTheCodeExecutionContainerIsReusedWhenAPausedTurnResumes(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_pause',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'bash_code_execution', 'input' => (object) ['command' => 'ls']],
                    ],
                    'container' => ['id' => 'container_123', 'expires_at' => '2026-11-08T11:00:00Z'],
                    'stop_reason' => 'pause_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Ask', provider: 'anthropic');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);
        $this->assertSame('container_123', $recorded[1][0]->data()['container']);
    }

    #[DataProvider('providedContainers')]
    public function testAContainerSetWithProviderOptionsCarriesOverToTheFollowUpRequest(array|string $container, array|string $followUp): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_123',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => new stdClass],
                    ],
                    'container' => ['id' => 'container_123', 'expires_at' => '2026-11-08T11:00:00Z'],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $agent = new class($container) implements Agent, HasProviderOptions, HasTools {
            use Promptable;

            /**
             * Create an agent that sends the given container.
             */
            public function __construct(protected array|string $container)
            {
            }

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the agent's tools.
             */
            public function tools(): iterable
            {
                return [new FixedNumberGenerator];
            }

            /**
             * Get the provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return ['container' => $this->container];
            }
        };

        $agent->prompt('Generate a random number', provider: 'anthropic');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);
        $this->assertSame($container, $recorded[0][0]->data()['container']);
        $this->assertSame($followUp, $recorded[1][0]->data()['container']);
    }

    /**
     * Provide containers set through provider options and the container each follow-up sends.
     */
    public static function providedContainers(): array
    {
        return [
            'skills' => [
                ['skills' => [['type' => 'anthropic', 'skill_id' => 'xlsx', 'version' => 'latest']]],
                ['skills' => [['type' => 'anthropic', 'skill_id' => 'xlsx', 'version' => 'latest']], 'id' => 'container_123'],
            ],
            'container id' => ['container_user', 'container_user'],
        ];
    }

    public function testToolCallsDoNotRunWhenTheMessageEndsWithMaxTokens(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => 'Let me generate a number.'],
                    ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => new stdClass],
                ],
                'stop_reason' => 'max_tokens',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'anthropic');

        $this->assertCount(1, Http::recorded());
    }

    public function testServerToolUseInputIsSerializedAsObjectOnFollowUpReplay(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_123',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        ['type' => 'server_tool_use', 'id' => 'srvtoolu_123', 'name' => 'advisor', 'input' => (object) []],
                        ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'anthropic');

        $followUpBody = Http::recorded()[1][0]->body();

        $this->assertStringContainsString('"type":"server_tool_use"', $followUpBody);
        $this->assertStringNotContainsString('"input":[]', $followUpBody);
    }

    public function testPauseTurnResumesWhenLastBlockIsTextFollowingAWebSearchToolResult(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_pause',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        [
                            'type' => 'server_tool_use',
                            'id' => 'srvtoolu_1',
                            'name' => 'web_search',
                            'input' => (object) ['query' => 'q'],
                        ],
                        [
                            'type' => 'web_search_tool_result',
                            'tool_use_id' => 'srvtoolu_1',
                            'content' => [],
                        ],
                        ['type' => 'text', 'text' => 'Paused mid-summary.'],
                    ],
                    'stop_reason' => 'pause_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Ask',
            provider: 'anthropic',
        );

        $this->assertCount(2, Http::recorded());
    }

    public function testFullComposePauseTurnAndServerToolUseReplayWithInputCastAndBlockOrderPreserved(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_compose',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [
                        [
                            'type' => 'server_tool_use',
                            'id' => 'srvtoolu_1',
                            'name' => 'advisor',
                            'input' => (object) [],
                        ],
                        [
                            'type' => 'advisor_tool_result',
                            'tool_use_id' => 'srvtoolu_1',
                            'content' => ['type' => 'advisor_result', 'text' => 'Plan.'],
                        ],
                        ['type' => 'text', 'text' => 'Consulting again.'],
                        [
                            'type' => 'server_tool_use',
                            'id' => 'srvtoolu_2',
                            'name' => 'advisor',
                            'input' => (object) [],
                        ],
                    ],
                    'stop_reason' => 'pause_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Compose test',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        $payload = json_decode((string) $recorded[1][0]->body(), false, 512, JSON_THROW_ON_ERROR);
        $assistant = collect($payload->messages)->first(fn ($m): bool => $m->role === 'assistant');

        $this->assertSame([
            'server_tool_use',
            'advisor_tool_result',
            'text',
            'server_tool_use',
        ], array_column((array) $assistant->content, 'type'));

        $serverBlocks = collect($assistant->content)->where('type', 'server_tool_use')->values();
        $this->assertCount(2, $serverBlocks);
        $this->assertInstanceOf(stdClass::class, $serverBlocks[0]->input);
        $this->assertInstanceOf(stdClass::class, $serverBlocks[1]->input);
    }

    public function testMaxStepsLimitsToolCallDepth(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();

        $this->assertLessThanOrEqual(3, count($recorded));
    }
}
