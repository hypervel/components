<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Gateway\StepResult;
use Hypervel\Ai\PendingStep;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Tools\AgentTool;
use Hypervel\Tests\Ai\Fixtures\Agents\DelegatingAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\MiddleManagerAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\OrchestratorAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ResearchAgent;
use Hypervel\Tests\Ai\TestCase;

class SubAgentTest extends TestCase
{
    public function testAgentReturnedFromToolsIsInvokedWhenCalledByParentAgent(): void
    {
        DelegatingAgent::fake([
            new ToolCall('call_123', 'research_agent', ['task' => 'Research Hypervel']),
            'Research delegated.',
        ]);

        ResearchAgent::fake(['Research result']);

        $response = (new DelegatingAgent)->prompt('Delegate research about Hypervel');

        DelegatingAgent::assertPrompted('Delegate research about Hypervel');
        ResearchAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Research Hypervel');

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('research_agent', $response->toolCalls->first()->name);
        $this->assertCount(1, $response->toolResults);
        $this->assertSame('Research result', $response->toolResults->first()->result);
    }

    public function testResearchAgentCanBeFakedIndependently(): void
    {
        ResearchAgent::fake(['Research result']);

        $response = (new ResearchAgent)->prompt('Research topic');

        $this->assertSame('Research result', $response->text);
    }

    public function testAgentToolUsesNameAndDescriptionFromAgentWhenDefined(): void
    {
        $tool = new AgentTool(new ResearchAgent);

        $this->assertSame('research_agent', $tool->name());
        $this->assertSame('Research a topic in depth and return a summary.', $tool->description());
    }

    public function testAgentToolFallsBackToClassBasenameForNameWhenHasToolMetadataIsNotImplemented(): void
    {
        $agent = new class implements Agent {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return '';
            }
        };

        $tool = new AgentTool($agent);
        $name = $tool->name();

        $this->assertNotEmpty($name);
        $this->assertStringStartsWith("Delegates a task to the {$name} sub-agent", (string) $tool->description());
    }

    public function testAgentToolFallsBackToAGenericDescriptionThatDoesNotLeakInstructions(): void
    {
        $agent = new class implements Agent {
            use Promptable;

            /**
             * Get the agent's instructions.
             */
            public function instructions(): string
            {
                return 'You are a silent agent with very long internal instructions that should never be sent to the parent LLM as a tool description.';
            }
        };

        $tool = new AgentTool($agent);

        $this->assertStringStartsWith('Delegates a task to the', (string) $tool->description());
        $this->assertStringContainsString('sub-agent', (string) $tool->description());
        $this->assertStringNotContainsString('long internal instructions', (string) $tool->description());
    }

    public function testFrameworkWrapsAnAgentInToolsAutomaticallyWhenResolving(): void
    {
        DelegatingAgent::fake(['Done.']);
        $resolved = [];

        (new DelegatingAgent)->withMiddleware([
            function (PendingStep $step, Closure $next) use (&$resolved): StepResult {
                $resolved = $step->tools;

                return $next($step);
            },
        ])->prompt('Research the topic');

        $this->assertInstanceOf(AgentTool::class, $resolved[0]);
        $this->assertInstanceOf(ResearchAgent::class, $resolved[0]->agent());
    }

    public function testNestedAgentDelegatesThroughAMiddleManagerToAResearchAgent(): void
    {
        OrchestratorAgent::fake([
            new ToolCall('call_001', 'middle_manager', ['task' => 'Deep-dive on Hypervel caching']),
            'Delegated to middle manager.',
        ]);

        MiddleManagerAgent::fake([
            new ToolCall('call_002', 'research_agent', ['task' => 'Research Hypervel caching internals']),
            'Research delegated.',
        ]);

        ResearchAgent::fake(['Deep research result']);

        $response = (new OrchestratorAgent)->prompt('Do a deep dive on Hypervel caching');

        OrchestratorAgent::assertPrompted('Do a deep dive on Hypervel caching');
        MiddleManagerAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Deep-dive on Hypervel caching');
        ResearchAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Research Hypervel caching internals');

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('middle_manager', $response->toolCalls->first()->name);
        $this->assertCount(1, $response->toolResults);
        $this->assertSame('Research delegated.', $response->toolResults->first()->result);
    }
}
