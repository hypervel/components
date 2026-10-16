<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Attributes\MaxSteps;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasMiddleware;
use Hypervel\Ai\Contracts\HasSkills;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Ai\Gateway\StepResult;
use Hypervel\Ai\PendingStep;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Skills\Skill;
use Hypervel\Ai\Tools\LoadSkill;
use Hypervel\Ai\Vercel\Vercel;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Log;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NamedToolAgent;
use Hypervel\Tests\Ai\Fixtures\Tools\ApprovableNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\TestCase;

class AgentWithToolsTest extends TestCase
{
    public function testRuntimeToolsReplaceTheToolsTheAgentDeclares(): void
    {
        NamedToolAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'Done.',
        ]);

        $response = (new NamedToolAgent)
            ->withTools([new FixedNumberGenerator])
            ->prompt('Generate a number');

        $this->assertSame(['72019'], $response->toolResults->pluck('result')->all());
    }

    public function testARuntimeToolClosureReceivesTheDeclaredTools(): void
    {
        NamedToolAgent::fake([
            new ToolCall('call_1', 'custom_named_tool', []),
            new ToolCall('call_2', 'FixedNumberGenerator', []),
            'Done.',
        ]);

        $response = (new NamedToolAgent)
            ->withTools(fn (array $tools): array => [...$tools, new FixedNumberGenerator])
            ->prompt('Use both tools');

        $this->assertSame(['ok', '72019'], $response->toolResults->pluck('result')->all());
    }

    public function testRuntimeToolsReachAnAgentThatDoesNotImplementHasTools(): void
    {
        $agent = new class implements Agent {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }
        };

        Ai::fakeAgent($agent::class, [
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'Done.',
        ]);

        $response = $agent->withTools([new FixedNumberGenerator])->prompt('Generate a number');

        $this->assertSame('72019', $response->toolResults->first()->result);
    }

    public function testRuntimeToolsPersistAcrossInvocationsOfTheSameInstance(): void
    {
        $agent = (new AssistantAgent)->withTools([new FixedNumberGenerator]);

        foreach (range(1, 2) as $attempt) {
            AssistantAgent::fake([
                new ToolCall('call_' . $attempt, 'FixedNumberGenerator', []),
                'Done.',
            ]);

            $this->assertSame('72019', $agent->prompt('Generate a number')->toolResults->first()->result);
        }
    }

    public function testWithToolsIsChainableAndTheLastCallWins(): void
    {
        NamedToolAgent::fake([
            new ToolCall('call_1', 'custom_named_tool', []),
            'Done.',
        ]);

        $response = (new NamedToolAgent)
            ->withTools([new FixedNumberGenerator])
            ->withTools([new NamedTool])
            ->prompt('Use the tool');

        $this->assertSame('ok', $response->toolResults->first()->result);
    }

    public function testAPausedApprovalResumesOnlyWhenTheRuntimeToolsAreReApplied(): void
    {
        ApprovableNumberGenerator::$invocations = 0;

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $chat = Vercel::chat([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Generate a number']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-ApprovableNumberGenerator', 'toolCallId' => 'toolu_1', 'state' => 'approval-responded', 'input' => [], 'approval' => ['id' => 'toolu_1', 'approved' => true]],
            ]],
        ]);

        $this->assertThrows(fn (): AgentResponse => (new AssistantAgent)
            ->withMessages($chat->history())
            ->prompt($chat, provider: 'anthropic'), NoSuchToolException::class);

        $response = (new AssistantAgent)
            ->withTools([new ApprovableNumberGenerator])
            ->withMessages($chat->history())
            ->prompt($chat, provider: 'anthropic');

        $this->assertSame(1, ApprovableNumberGenerator::$invocations);
        $this->assertSame('The number is 72019.', $response->text);
    }

    public function testAnUngatedToolCallInTheResumedHistoryIsSettledInsteadOfExecuted(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Done.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $chat = Vercel::chat([
            ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Generate a number']]],
            ['id' => 'm2', 'role' => 'assistant', 'parts' => [
                ['type' => 'tool-FixedNumberGenerator', 'toolCallId' => 'toolu_1', 'state' => 'approval-responded', 'input' => [], 'approval' => ['id' => 'toolu_1', 'approved' => true]],
            ]],
        ]);

        (new AssistantAgent)
            ->withTools([new FixedNumberGenerator(throwsException: true)])
            ->withMessages($chat->history())
            ->prompt($chat, provider: 'anthropic');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'was not pending approval')
            && ! str_contains($request->body(), 'Forced to throw'));
    }

    public function testMiddlewareMaySwapTheToolsForAStep(): void
    {
        $agent = new class implements Agent, HasMiddleware, HasTools {
            use Promptable;

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
                return [new NamedTool];
            }

            /**
             * Get the agent's middleware.
             */
            public function middleware(): array
            {
                return [
                    fn (PendingStep $step, Closure $next): StepResult => $next($step->withTools([new FixedNumberGenerator])),
                ];
            }
        };

        Ai::fakeAgent($agent::class, [
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'Done.',
        ]);

        $this->assertSame('72019', $agent->prompt('Generate a number')->toolResults->first()->result);
    }

    public function testAResumePromptKeepsItsToolsWhenMiddlewareAttemptsASwap(): void
    {
        $prompt = new AgentPrompt(
            new AssistantAgent,
            '',
            [],
            Ai::textProviderFor(new AssistantAgent, 'anthropic'),
            'claude-sonnet-4-6',
            approvalDecisions: Decisions::from(['toolu_1' => true]),
            tools: [new ApprovableNumberGenerator],
        );

        $this->assertSame($prompt, $prompt->withTools([new NamedTool]));
    }

    public function testAnAgentWithSkillsReceivesTheLoadSkillTool(): void
    {
        $agent = new class implements Agent, HasSkills {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get the agent's skills.
             */
            public function skills(): iterable
            {
                return [new Skill('pdf', 'Extract PDF text.', 'Use pdftotext.')];
            }
        };

        Ai::fakeAgent($agent::class, [
            new ToolCall('call_1', 'LoadSkill', ['name' => 'pdf']),
            'Done.',
        ]);

        $this->assertSame("<skill_content name=\"pdf\">\nUse pdftotext.\n</skill_content>", $agent->prompt('Read the PDF')->toolResults->first()->result);
    }

    public function testAnAgentDeclaringALoadSkillToolHasItsSkillsMergedIntoItWithAWarning(): void
    {
        $agent = new #[MaxSteps(3)] class implements Agent, HasSkills, HasTools {
            use Promptable;

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
                return [new LoadSkill([new Skill('pdf', 'Extract PDF text.', 'Use pdftotext.')])];
            }

            /**
             * Get the agent's skills.
             */
            public function skills(): iterable
            {
                return [new Skill('csv', 'Parse CSV files.', 'Use fgetcsv.')];
            }
        };

        Log::spy();

        Ai::fakeAgent($agent::class, [
            new ToolCall('call_1', 'LoadSkill', ['name' => 'pdf']),
            new ToolCall('call_2', 'LoadSkill', ['name' => 'csv']),
            'Done.',
        ]);

        $this->assertSame([
            "<skill_content name=\"pdf\">\nUse pdftotext.\n</skill_content>",
            "<skill_content name=\"csv\">\nUse fgetcsv.\n</skill_content>",
        ], $agent->prompt('Read both files')->toolResults->pluck('result')->all());

        Log::shouldHaveReceived('warning')->once()->with('Agent [' . $agent::class . '] declares both skills and a LoadSkill tool; its skills were merged into that tool.');
    }
}
