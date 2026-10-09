<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\StartingStep;
use Hypervel\Ai\Events\StepCompleted;
use Hypervel\Ai\Events\StepFailed;
use Hypervel\Ai\Events\ToolFailed;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Contracts\Events\Dispatcher;
use Throwable;

class RunContext
{
    /** @var array<int, Step> */
    protected array $steps = [];

    /**
     * Create the context for a generation run.
     *
     * @param null|Closure(Closure): mixed $contextRunner
     */
    public function __construct(
        public readonly string $invocationId,
        public readonly Agent $agent,
        public readonly TextProvider $provider,
        public readonly string $model,
        protected readonly Dispatcher $events,
        protected readonly ?Closure $contextRunner = null,
    ) {
    }

    /**
     * Get the captured operation context runner.
     *
     * @return null|Closure(Closure): mixed
     *
     * @internal
     */
    public function contextRunner(): ?Closure
    {
        return $this->contextRunner;
    }

    /**
     * Keep the step the model just produced, so a run that dies later can still be recorded as far as it got.
     */
    public function recordStep(Step $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * Answer the step being worked on, one tool at a time, so a step that dies partway keeps the tools that ran.
     */
    public function recordToolResult(ToolResult $result): void
    {
        $step = array_key_last($this->steps);

        if ($step !== null) {
            $this->steps[$step]->toolResults[] = $result;
        }
    }

    /**
     * Get the response the run had built by the time it ended, however it ended.
     */
    public function recordedResponse(): AgentResponse
    {
        $last = $this->steps === [] ? null : $this->steps[array_key_last($this->steps)];

        return tap(new AgentResponse(
            $this->invocationId,
            $last->text ?? '',
            collect($this->steps)->reduce(fn (TextUsage $total, Step $step): TextUsage => $total->add($step->usage), new TextUsage),
            $last->meta ?? new Meta($this->provider->name(), $this->model),
        ), fn (AgentResponse $response): AgentResponse => $response->withSteps(collect($this->steps)));
    }

    /**
     * Report that a generation step is about to start.
     *
     * @param Message[] $messages
     */
    public function startingStep(StepContext $step, array $messages, ?TextGenerationOptions $options, ?string $model = null): void
    {
        if (! $this->events->hasListeners(StartingStep::class)) {
            return;
        }

        $this->events->dispatch(new StartingStep(
            $this->invocationId,
            $step->stepNumber,
            $this->agent,
            $this->provider,
            $model ?? $this->model,
            $step->isFinalStep,
            $messages,
            $options,
        ));
    }

    /**
     * Report that a generation step returned a response.
     */
    public function stepCompleted(?StepContext $step, StepResponse $response, float $time, ?string $model = null): void
    {
        if ($step === null || ! $this->events->hasListeners(StepCompleted::class)) {
            return;
        }

        $this->events->dispatch(new StepCompleted(
            $this->invocationId,
            $step->stepNumber,
            $this->agent,
            $this->provider,
            $model ?? $this->model,
            $step->isFinalStep,
            $response,
            $time,
        ));
    }

    /**
     * Report that a generation step ended without producing a response.
     */
    public function stepFailed(?StepContext $step, Throwable $exception, float $time, ?string $model = null): void
    {
        if ($step === null || ! $this->events->hasListeners(StepFailed::class)) {
            return;
        }

        $this->events->dispatch(new StepFailed(
            $this->invocationId,
            $step->stepNumber,
            $this->agent,
            $this->provider,
            $model ?? $this->model,
            $step->isFinalStep,
            $exception,
            $time,
        ));
    }

    /**
     * Report that a tool is about to be invoked.
     *
     * @param array<string, mixed> $arguments
     */
    public function invokingTool(Tool $tool, array $arguments, string $toolInvocationId): void
    {
        if (! $this->events->hasListeners(InvokingTool::class)) {
            return;
        }

        $this->events->dispatch(new InvokingTool(
            $this->invocationId,
            $toolInvocationId,
            $this->agent,
            $tool,
            $arguments,
        ));
    }

    /**
     * Report that a tool returned a result.
     *
     * @param array<string, mixed> $arguments
     */
    public function toolInvoked(Tool $tool, array $arguments, mixed $result, string $toolInvocationId, float $time): void
    {
        if (! $this->events->hasListeners(ToolInvoked::class)) {
            return;
        }

        $this->events->dispatch(new ToolInvoked(
            $this->invocationId,
            $toolInvocationId,
            $this->agent,
            $tool,
            $arguments,
            $result,
            $time,
        ));
    }

    /**
     * Report that a tool's handler threw.
     *
     * @param array<string, mixed> $arguments
     */
    public function toolFailed(Tool $tool, array $arguments, Throwable $exception, string $toolInvocationId, float $time): void
    {
        if (! $this->events->hasListeners(ToolFailed::class)) {
            return;
        }

        $this->events->dispatch(new ToolFailed(
            $this->invocationId,
            $toolInvocationId,
            $this->agent,
            $tool,
            $arguments,
            $exception,
            $time,
        ));
    }
}
