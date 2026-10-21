<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\PromptingAgent;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Tools\AgentTool;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\Fixtures\Agents\DelegatingAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ResearchAgent;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class MultiAgentTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'agents')]
    public function testAParentAgentDelegatesToASubAgentAndTheSubAgentRunsEndToEnd(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        config(['ai.default' => $provider]);

        Event::fake();

        $response = (new DelegatingAgent)->prompt(
            'Use the research_agent tool to answer: "What is Hypervel?" Forward the user question verbatim as the task.',
            provider: $provider,
            model: $model,
        );

        $researchToolCall = $response->toolCalls->firstWhere('name', 'research_agent');

        $this->assertNotNull($researchToolCall);
        $this->assertArrayHasKey('task', $researchToolCall->arguments);
        $this->assertIsString($researchToolCall->arguments['task']);
        $this->assertNotEmpty($researchToolCall->arguments['task']);

        Event::assertDispatched(InvokingTool::class, fn (InvokingTool $event): bool => $event->tool instanceof AgentTool
            && $event->tool->agent() instanceof ResearchAgent
            && ($event->arguments['task'] ?? null) === $researchToolCall->arguments['task']);

        $researchPrompted = null;

        Event::assertDispatched(AgentPrompted::class, function (AgentPrompted $event) use (&$researchPrompted): bool {
            if ($event->prompt->agent instanceof ResearchAgent) {
                $researchPrompted = $event;

                return true;
            }

            return false;
        });

        $this->assertNotNull($researchPrompted);
        $this->assertSame($researchToolCall->arguments['task'], $researchPrompted->prompt->prompt);
        $this->assertIsString($researchPrompted->response->text);
        $this->assertNotEmpty($researchPrompted->response->text);

        Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->prompt->agent instanceof ResearchAgent);

        $researchToolResult = $response->toolResults->firstWhere('name', 'research_agent');

        $this->assertNotNull($researchToolResult);
        $this->assertSame($researchPrompted->response->text, $researchToolResult->result);

        Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->tool instanceof AgentTool
            && $event->tool->agent() instanceof ResearchAgent
            && $event->result === $researchPrompted->response->text);
    }
}
