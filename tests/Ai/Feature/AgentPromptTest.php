<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;

class AgentPromptTest extends TestCase
{
    public function testQueuedListenersReceivePromptDataWithoutLiveExecutionState(): void
    {
        Queue::fake()->serializeAndRestore();
        Event::listen(AgentPrompted::class, PromptUsageListener::class);

        $agent = self::createStub(Agent::class);
        $provider = self::createConfiguredStub(TextProvider::class, ['name' => 'test-provider']);
        $runner = static fn (Closure $work): mixed => $work();
        $prompt = new AgentPrompt($agent, 'Summarize this', [], $provider, 'test-model', contextRunner: $runner);
        $context = new RunContext('invocation', $agent, $provider, 'test-model', $this->app->make('events'), $runner);
        $prompt->setRunContext($context);
        $response = new AgentResponse('invocation', 'Summary', new TextUsage, new Meta);

        event(new AgentPrompted('invocation', $prompt, $response));

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            $restored = $job->data[0]->prompt;

            $this->assertNull($restored->runContext());
            $this->assertNull($restored->contextRunner());
            $this->assertSame('Summarize this', $restored->prompt);
            $this->assertSame('test-provider', $restored->provider()->name());

            return true;
        });

        $this->assertSame($runner, $prompt->contextRunner());
        $this->assertSame($context, $prompt->runContext());
    }

    public function testPromptAndToolRevisionsPreserveTheCapturedContext(): void
    {
        $runner = static fn (Closure $work): mixed => $work();
        $prompt = new AgentPrompt(
            self::createStub(Agent::class),
            'Hello',
            [],
            self::createStub(TextProvider::class),
            'test-model',
            contextRunner: $runner,
        );

        $this->assertSame($runner, $prompt->prepend('Instructions')->contextRunner());
        $this->assertSame($runner, $prompt->withTools([])->contextRunner());
    }
}

class PromptUsageListener implements ShouldQueue
{
    /**
     * Handle the queued usage event.
     */
    public function handle(AgentPrompted $event): void
    {
    }
}
