<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Approvals\ApprovalClaim;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\ClaimsPendingApprovals;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\StartingStep;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Gateway\StepResult;
use Hypervel\Ai\PendingStep;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Support\PendingConversationTitle;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Tests\Ai\TestCase;
use Laravel\SerializableClosure\SerializableClosure;

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
        $claim = new ApprovalClaim('message', 'token');
        $store = self::createConfiguredStub(ClaimsPendingApprovals::class, ['claimPendingApprovals' => $claim]);
        $context = new RunContext('invocation', $agent, $provider, 'test-model', $this->app->make('events'), $runner, $store, 'conversation');
        $context->claimPendingApprovals(['call']);
        $prompt->setRunContext($context);
        $prompt->setPendingConversationTitle($title = new PendingConversationTitle(fn (): string => 'Conversation title', $runner));
        $title->value();
        $response = new AgentResponse('invocation', 'Summary', new TextUsage, new Meta);

        event(new AgentPrompted('invocation', $prompt, $response));

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            $restored = $job->data[0]->prompt;

            $this->assertNull($restored->runContext());
            $this->assertNull($restored->approvalClaim());
            $this->assertNull($restored->contextRunner());
            $this->assertNull($restored->pendingConversationTitle());
            $this->assertSame('Summarize this', $restored->prompt);
            $this->assertSame('test-provider', $restored->provider()->name());

            return true;
        });

        $this->assertSame($runner, $prompt->contextRunner());
        $this->assertSame($context, $prompt->runContext());
        $this->assertSame($claim, $prompt->approvalClaim());
        $this->assertSame($title, $prompt->pendingConversationTitle());
    }

    public function testPromptAndToolRevisionsPreserveTheCapturedContextAndMiddleware(): void
    {
        $runner = static fn (Closure $work): mixed => $work();
        $middleware = static fn (PendingStep $step, Closure $next): StepResult => $next($step);
        $prompt = new AgentPrompt(
            self::createStub(Agent::class),
            'Hello',
            [],
            self::createStub(TextProvider::class),
            'test-model',
            contextRunner: $runner,
            middleware: [$middleware],
        );
        $prompt->markAsStreaming();

        $this->assertSame($runner, $prompt->prepend('Instructions')->contextRunner());
        $this->assertSame($runner, $prompt->withTools([])->contextRunner());
        $this->assertTrue($prompt->prepend('Instructions')->isStreaming());
        $this->assertTrue($prompt->withTools([])->isStreaming());
        $this->assertSame($prompt->middleware, $prompt->prepend('Instructions')->middleware);
        $this->assertSame($prompt->middleware, $prompt->withTools([])->middleware);
        $restored = unserialize(serialize($prompt->withTools([])));
        $this->assertInstanceOf(SerializableClosure::class, $restored->middleware[0]);
    }

    public function testQueuedListenersSerializeRuntimeMiddlewareOnPromptsAndStepOptions(): void
    {
        Queue::fake()->serializeAndRestore();
        Event::listen(AgentPrompted::class, PromptUsageListener::class);
        Event::listen(StartingStep::class, StepUsageListener::class);
        AnonymousAgent::fake(['Answer']);

        (new AnonymousAgent('Be helpful.', [], []))->withMiddleware([
            static fn (PendingStep $step, Closure $next): StepResult => $next($step->withModel('wrapped-model')),
        ])->prompt('Hello', provider: 'anthropic');

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            if ($job->class !== PromptUsageListener::class) {
                return false;
            }

            $this->assertSame('wrapped-model', $job->data[0]->response->meta->model);
            $this->assertCount(1, $job->data[0]->prompt->middleware);

            return true;
        });
        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            if ($job->class !== StepUsageListener::class) {
                return false;
            }

            $this->assertSame('wrapped-model', $job->data[0]->model);
            $this->assertCount(1, $job->data[0]->options->middleware);

            return true;
        });
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

class StepUsageListener implements ShouldQueue
{
    /**
     * Handle the queued step event.
     */
    public function handle(StartingStep $event): void
    {
    }
}
