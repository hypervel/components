<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Generator;
use Hypervel\Ai\Ai;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\Approvals\Approval;
use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Attributes\ConcurrentTools;
use Hypervel\Ai\Attributes\RepairToolCalls;
use Hypervel\Ai\Concerns\JoinsReasoning;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\HasMiddleware;
use Hypervel\Ai\Contracts\Providers\SupportsCodeExecution;
use Hypervel\Ai\Contracts\Providers\SupportsFileSearch;
use Hypervel\Ai\Contracts\Providers\SupportsToolSearch;
use Hypervel\Ai\Contracts\Providers\SupportsWebFetch;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Gateway\Concerns\HandlesToolApprovals;
use Hypervel\Ai\Gateway\Concerns\InvokesTools;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\PendingStep;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\FileSearch;
use Hypervel\Ai\Providers\Tools\ProviderTool;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Responses\StructuredTextResponse;
use Hypervel\Ai\Responses\TextResponse;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Hypervel\Ai\Tools\AgentTool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Ai\Tools\ToolNameResolver;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Engine\Channel;
use Hypervel\Support\ClassMetadataCache;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use LogicException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class TextGenerationLoop
{
    use HandlesToolApprovals;
    use InvokesTools;
    use JoinsReasoning;

    /**
     * The characters a tool must add before its unfinished output is reported again.
     */
    private const int PRELIMINARY_OUTPUT_BYTES = 240;

    private const string REPAIR_TOOL_CALLS_CONTEXT_KEY = '__ai.repair_tool_calls';

    private const string TOOL_CONCURRENCY_CONTEXT_KEY = '__ai.tool_concurrency';

    /**
     * The default ceiling for the derived step budget when it is not set explicitly.
     */
    protected const int DEFAULT_MAX_STEPS = 25;

    /**
     * Create a new text generation loop instance.
     */
    public function __construct(protected StepTextGateway $gateway)
    {
    }

    /**
     * Generate a response through model and tool steps.
     *
     * @param array<ProviderTool|Tool> $tools
     * @param null|array<string, mixed> $schema
     * @param null|array<string, Decision> $approval
     */
    public function generate(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?array $approval = null,
        ?Closure $recordApprovalResults = null,
        ?RunContext $context = null,
    ): TextResponse {
        $this->ensureSingleToolSearch($tools);

        $tools = $this->toolsSupportedBy($provider, $tools);

        $middleware = $this->middlewareFor($options);
        $steps = new Collection;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $previous = null;
        $accumulatedUsage = new TextUsage;
        $lastResult = null;

        if ($approval !== null) {
            $resumption = $this->resumeFromApproval($approval, $messages, $tools, context: $context);

            $allMessages = $resumption->messages;
            $newMessages = $resumption->newMessages;

            if ($recordApprovalResults !== null) {
                $recordApprovalResults($resumption->results);
            }

            if (! $resumption->shouldContinue) {
                return (new TextResponse('', new TextUsage, new Meta($provider->name(), $model)))
                    ->withMessages(collect($newMessages));
            }
        } else {
            $allMessages = $this->settleAbandonedToolCalls($messages);
            $newMessages = [];
        }

        for ($step = 0; $step < $maxSteps; ++$step) {
            $pending = new PendingStep(
                number: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                provider: $provider->name(),
                model: $model,
                instructions: $instructions,
                messages: $allMessages,
                tools: $tools,
                schema: $schema,
                options: $options?->forStep($step),
                steps: $steps->all(),
                usage: $accumulatedUsage,
                timeout: $timeout,
                invocationId: $context?->invocationId,
            );

            // Held by reference because a short-circuiting middleware returns a result that is not the attempt...
            $attempt = null;

            try {
                $lastResult = $this->runStep($pending, $middleware, function (PendingStep $step) use ($provider, $previous, $context, $allMessages, $continuationToken, &$attempt): StepResult {
                    return $attempt = $this->attempt($step, $previous, $allMessages, $continuationToken, $context, fn (StepContext $stepContext): StepResponse => $this->gateway->generateTextStep(
                        $provider,
                        $step->model,
                        $step->instructions,
                        $step->messages,
                        $step->tools,
                        $step->schema,
                        $step->options,
                        $step->timeout,
                        $stepContext,
                    ));
                })->response();
            } catch (Throwable $exception) {
                $this->stepFailed($context, $attempt, $exception);

                throw $exception;
            }

            $prepared = $attempt->step ?? $pending;

            // Recorded before the tools run so a step that dies partway is still kept as far as it got...
            $steps->push($completedStep = $this->buildStep($lastResult));

            $context?->recordStep($completedStep);

            $this->stepCompleted($context, $attempt, $lastResult);

            $accumulatedUsage = $accumulatedUsage->add($lastResult->usage);

            [$toolResults, $pendingApprovals] = $this->stepToolResultsWithOptions($lastResult, $prepared->isFinalStep, $prepared->tools, $prepared->options, $context);

            $completedStep->toolResults = $toolResults;

            $assistantMessage = $this->buildAssistantMessage($lastResult);
            $allMessages[] = $assistantMessage;
            $newMessages[] = $assistantMessage;

            if (filled($toolResults)) {
                $toolResultMessage = new ToolResultMessage(collect($toolResults));
                $allMessages[] = $toolResultMessage;
                $newMessages[] = $toolResultMessage;
            }

            if ($pendingApprovals->isNotEmpty()) {
                return $this->buildFinalResponse($steps, $newMessages, $lastResult)
                    ->withPendingApprovals($pendingApprovals);
            }

            if (blank($toolResults) && $lastResult->finishReason !== FinishReason::Continue) {
                break;
            }

            $continuationToken = $lastResult->continuationToken;
            $previous = $prepared;
        }

        return $this->buildFinalResponse($steps, $newMessages, $lastResult);
    }

    /**
     * Stream a response through model and tool steps.
     *
     * @param array<ProviderTool|Tool> $tools
     * @param null|array<string, mixed> $schema
     * @param null|array<string, Decision> $approval
     * @param null|array{Collection<int, ToolCall>, Collection<string, ?Tool>} $validatedApproval the caller's own eager validateApproval() result, reused instead of re-validating
     */
    public function stream(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?array $approval = null,
        ?Closure $recordApprovalResults = null,
        ?array $validatedApproval = null,
        ?RunContext $context = null,
    ): Generator {
        $this->ensureSingleToolSearch($tools);

        $tools = $this->toolsSupportedBy($provider, $tools);

        $middleware = $this->middlewareFor($options);
        $steps = new Collection;
        $maxSteps = $this->resolveMaxSteps($options, $tools);
        $continuationToken = null;
        $previous = null;
        $accumulatedUsage = new TextUsage;
        $finalReason = null;

        if ($approval !== null) {
            $resumption = $this->resumeFromApproval($approval, $messages, $tools, $validatedApproval, $context);

            $allMessages = $resumption->messages;

            if ($recordApprovalResults !== null) {
                $recordApprovalResults($resumption->results);
            }

            foreach ($resumption->results as $toolResult) {
                yield (new ToolResultEvent(
                    $this->generateEventId(),
                    $toolResult,
                    $toolResult->successful(),
                    $toolResult->error(),
                    time(),
                    denied: $toolResult->denied,
                ))->withInvocationId($invocationId);
            }

            if (! $resumption->shouldContinue) {
                yield (new StreamEnd(
                    $this->generateEventId(),
                    FinishReason::Stop->value,
                    $accumulatedUsage,
                    time(),
                ))->withInvocationId($invocationId);

                return;
            }
        } else {
            $allMessages = $this->settleAbandonedToolCalls($messages);
        }

        for ($step = 0; $step < $maxSteps; ++$step) {
            $pending = new PendingStep(
                number: $step,
                isFinalStep: $step + 1 >= $maxSteps,
                provider: $provider->name(),
                model: $model,
                instructions: $instructions,
                messages: $allMessages,
                tools: $tools,
                schema: $schema,
                options: $options?->forStep($step),
                steps: $steps->all(),
                usage: $accumulatedUsage,
                timeout: $timeout,
                invocationId: $context?->invocationId,
            );

            // Held by reference because a short-circuiting middleware returns a result that is not the attempt...
            $attempt = null;
            $lastError = null;

            try {
                $stepResult = $this->runStep($pending, $middleware, function (PendingStep $step) use ($invocationId, $provider, $previous, $context, $allMessages, $continuationToken, &$attempt): StepResult {
                    return $attempt = $this->attempt($step, $previous, $allMessages, $continuationToken, $context, function (StepContext $stepContext) use ($invocationId, $provider, $step): Generator {
                        return $this->gateway->generateStreamStep(
                            $invocationId,
                            $provider,
                            $step->model,
                            $step->instructions,
                            $step->messages,
                            $step->tools,
                            $step->schema,
                            $step->options,
                            $step->timeout,
                            $stepContext,
                        );
                    });
                });

                $reasoningDeltas = [];

                foreach ($stepResult as $event) {
                    yield $event;

                    if ($event instanceof Error) {
                        $lastError = $event;
                    }

                    if ($event instanceof ReasoningDelta) {
                        $reasoningDeltas[] = $event;
                    }
                }

                $prepared = $attempt->step ?? $pending;
                $result = $stepResult->response();

                if (! $stepResult->streamed() && $result instanceof StepResponse) {
                    yield from $this->eventsFor($invocationId, $provider, $prepared->model, $result);
                }

                if ($result instanceof StepResponse && $result->reasoning === '') {
                    $result->reasoning = ReasoningDelta::combine($reasoningDeltas);
                }
            } catch (Throwable $exception) {
                $this->stepFailed($context, $attempt, $exception);

                throw $exception;
            }

            // A provider may report an error in the stream itself rather than throwing, which still ends the step. The error event travels on the exception so its type and metadata are not lost...
            if (! $result instanceof StepResponse) {
                $exception = new StreamErrorException($lastError);

                $this->stepFailed($context, $attempt, $exception);

                throw $exception;
            }

            // Recorded before the tools run so a step that dies partway is still kept as far as it got...
            $steps->push($completedStep = $this->buildStep($result));

            $context?->recordStep($completedStep);

            $this->stepCompleted($context, $attempt, $result);

            $accumulatedUsage = $accumulatedUsage->add($result->usage);
            $finalReason = $result->finishReason;

            $toolStream = $this->streamedStepToolResults(
                $result,
                $prepared->isFinalStep,
                $prepared->tools,
                $invocationId,
                $prepared->options,
                $context,
            );

            // Re-yielded rather than delegated so every event keeps a distinct key and iterator_to_array() drops none of them...
            foreach ($toolStream as $event) {
                yield $event;
            }

            [$toolResults, $pendingApprovals] = $toolStream->getReturn();

            $completedStep->toolResults = $toolResults;

            foreach ($toolResults as $toolResult) {
                yield (new ToolResultEvent(
                    $this->generateEventId(),
                    $toolResult,
                    $toolResult->successful(),
                    $toolResult->error(),
                    time(),
                ))->withInvocationId($invocationId);
            }

            $allMessages[] = $this->buildAssistantMessage($result);

            if (filled($toolResults)) {
                $allMessages[] = new ToolResultMessage(collect($toolResults));
            }

            if ($pendingApprovals->isNotEmpty()) {
                yield (new ToolApprovalRequest(
                    $this->generateEventId(),
                    $pendingApprovals,
                    time(),
                    $steps,
                ))->withInvocationId($invocationId);

                break;
            }

            if (blank($toolResults) && $result->finishReason !== FinishReason::Continue) {
                break;
            }

            $continuationToken = $result->continuationToken;
            $previous = $prepared;
        }

        // A step that never produced a response has already thrown, so the loop only reaches here having set a reason...
        yield (new StreamEnd(
            $this->generateEventId(),
            ($finalReason ?? FinishReason::Stop)->value,
            $accumulatedUsage,
            time(),
            $steps,
        ))->withInvocationId($invocationId);
    }

    /**
     * The middleware wrapping each step, as declared by the agent being run.
     *
     * @return array<int, mixed>
     */
    protected function middlewareFor(?TextGenerationOptions $options): array
    {
        return $options?->agent instanceof HasMiddleware ? $options->agent->middleware() : [];
    }

    /**
     * Each middleware receives a StepResult from $next, whether the inner layer streamed, short-circuited or replaced the step.
     *
     * @param array<int, mixed> $middleware
     * @param Closure(PendingStep): StepResult $run
     */
    protected function runStep(PendingStep $step, array $middleware, Closure $run): StepResult
    {
        $next = $run;

        foreach (array_reverse($middleware) as $pipe) {
            $next = fn (PendingStep $step): StepResult => $this->toStepResult(
                $pipe instanceof Closure ? $pipe($step, $next) : (is_string($pipe) ? resolve($pipe) : $pipe)->handle($step, $next),
            );
        }

        return $next($step);
    }

    /**
     * Normalize a middleware result to a step result.
     */
    protected function toStepResult(mixed $result): StepResult
    {
        return match (true) {
            $result instanceof StepResult => $result,
            $result instanceof StepResponse => new StepResult($result),
            default => throw new LogicException('Agent middleware must return the next step result or a StepResponse.'),
        };
    }

    /**
     * Send the prepared step to the model, reporting its start and any synchronous failure.
     *
     * @param Message[] $history
     * @param Closure(StepContext): (Generator<int, StreamEvent, mixed, null|StepResponse>|StepResponse) $call
     */
    protected function attempt(PendingStep $step, ?PendingStep $previous, array $history, ?string $continuationToken, ?RunContext $context, Closure $call): StepResult
    {
        $stepContext = $this->stepContextFor($step, $previous, $history, $continuationToken);

        $context?->startingStep($stepContext, $step->messages, $step->options, $step->model);

        $startedAt = hrtime(true);

        try {
            $source = $call($stepContext);
        } catch (Throwable $exception) {
            $context?->stepFailed($stepContext, $exception, $this->elapsedMilliseconds($startedAt), $step->model);

            throw $exception;
        }

        return new StepResult($source, $step, $stepContext, $startedAt);
    }

    /**
     * A step that middleware answered itself was never attempted and reports nothing.
     */
    protected function stepCompleted(?RunContext $context, ?StepResult $attempt, StepResponse $response): void
    {
        if ($attempt !== null) {
            $context?->stepCompleted($attempt->context, $response, $this->elapsedMilliseconds($attempt->startedAt), $attempt->step->model);
        }
    }

    /**
     * Report a failed generation attempt when one was made.
     */
    protected function stepFailed(?RunContext $context, ?StepResult $attempt, Throwable $exception): void
    {
        if ($attempt !== null) {
            $context?->stepFailed($attempt->context, $exception, $this->elapsedMilliseconds($attempt->startedAt), $attempt->step->model);
        }
    }

    /**
     * A continuation replays only unchanged history, model and instructions.
     *
     * @param Message[] $history
     */
    protected function stepContextFor(PendingStep $step, ?PendingStep $previous, array $history, ?string $continuationToken): StepContext
    {
        $unchanged = $step->messages === $history && $step->model === $previous?->model && $step->instructions === $previous->instructions;

        return new StepContext(
            stepNumber: $step->number,
            isFinalStep: $step->isFinalStep,
            continuationToken: $unchanged ? $continuationToken : null,
        );
    }

    /**
     * The stream events describing a step response that was produced without streaming.
     *
     * @return Generator<int, StreamEvent>
     */
    protected function eventsFor(string $invocationId, TextProvider $provider, string $model, StepResponse $response): Generator
    {
        yield (new StreamStart($this->generateEventId(), $provider->name(), $model, time()))->withInvocationId($invocationId);

        if (filled($response->text)) {
            $messageId = $this->generateEventId();

            yield (new TextStart($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
            yield (new TextDelta($this->generateEventId(), $messageId, $response->text, time()))->withInvocationId($invocationId);
            yield (new TextEnd($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($response->toolCalls as $toolCall) {
            yield (new ToolCallEvent($this->generateEventId(), $toolCall, time()))->withInvocationId($invocationId);
        }
    }

    /**
     * Resolve the step budget: explicit `maxSteps`, else 1.5x tools capped at the ceiling, else 5.
     *
     * @param array<ProviderTool|Tool> $tools
     */
    protected function resolveMaxSteps(?TextGenerationOptions $options, array $tools): int
    {
        if ($options?->maxSteps !== null) {
            return max(1, $options->maxSteps);
        }

        $count = ToolSearch::budget($tools);
        $maxSteps = $count > 0 ? min((int) round($count * 1.5), self::DEFAULT_MAX_STEPS) : 5;

        return $maxSteps + (int) RepairToolCalls::isAppliedTo($options?->agent);
    }

    /**
     * Resolve tool results using the generation options for the current step.
     *
     * @param array<ProviderTool|Tool> $tools
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    private function stepToolResultsWithOptions(StepResponse $result, bool $isFinalStep, array $tools, ?TextGenerationOptions $options, ?RunContext $context = null): array
    {
        // Carry the option without changing or bypassing the protected tool-result extension signatures.
        $previous = CoroutineContext::get(self::TOOL_CONCURRENCY_CONTEXT_KEY);
        CoroutineContext::set(self::TOOL_CONCURRENCY_CONTEXT_KEY, $this->toolConcurrency($options));

        try {
            return $this->withRepairSetting(
                $options,
                fn (): array => $this->stepToolResults($result, $isFinalStep, $tools, $context),
            );
        } finally {
            if ($previous === null) {
                CoroutineContext::forget(self::TOOL_CONCURRENCY_CONTEXT_KEY);
            } else {
                CoroutineContext::set(self::TOOL_CONCURRENCY_CONTEXT_KEY, $previous);
            }
        }
    }

    /**
     * Get the agent's opted-in tool concurrency, retaining serial execution by default.
     */
    private function toolConcurrency(?TextGenerationOptions $options): int
    {
        if ($options?->agent === null) {
            return 1;
        }

        $attributes = ClassMetadataCache::reflectClass($options->agent)->getAttributes(ConcurrentTools::class);

        return $attributes === [] ? 1 : $attributes[0]->newInstance()->max;
    }

    /**
     * Run the given callback with the tool call repair setting the step's agent asks for.
     */
    private function withRepairSetting(?TextGenerationOptions $options, Closure $callback): mixed
    {
        $repairsToolCalls = CoroutineContext::get(self::REPAIR_TOOL_CALLS_CONTEXT_KEY);

        CoroutineContext::set(self::REPAIR_TOOL_CALLS_CONTEXT_KEY, RepairToolCalls::isAppliedTo($options?->agent));

        try {
            return $callback();
        } finally {
            if ($repairsToolCalls === null) {
                CoroutineContext::forget(self::REPAIR_TOOL_CALLS_CONTEXT_KEY);
            } else {
                CoroutineContext::set(self::REPAIR_TOOL_CALLS_CONTEXT_KEY, $repairsToolCalls);
            }
        }
    }

    /**
     * Tool results to continue the loop with, plus any pending approvals that pause it.
     *
     * @param array<ProviderTool|Tool> $tools
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    protected function stepToolResults(StepResponse $result, bool $isFinalStep, array $tools, ?RunContext $context = null): array
    {
        return $this->earlyStepToolResults($result)
            ?? $this->approvalAwareToolResults($result->toolCalls, $tools, $isFinalStep, $context);
    }

    /**
     * The early outcome for steps that pause or execute nothing, or null when tools should run.
     *
     * @return null|array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    protected function earlyStepToolResults(StepResponse $result): ?array
    {
        if (filled($result->pendingApprovals)) {
            return [[], collect($result->pendingApprovals)];
        }

        if ($result->finishReason !== FinishReason::ToolCalls || blank($result->toolCalls)) {
            return [[], collect()];
        }

        return null;
    }

    /**
     * Get tool results while streaming sub-agent activity.
     *
     * @param array<ProviderTool|Tool> $tools
     * @return Generator<int, ToolResultEvent, mixed, array{array<int, ToolResult>, Collection<int, PendingApproval>}>
     */
    protected function streamedStepToolResults(StepResponse $result, bool $isFinalStep, array $tools, string $invocationId, ?TextGenerationOptions $options = null, ?RunContext $context = null): Generator
    {
        if (($earlyOutcome = $this->earlyStepToolResults($result)) !== null) {
            return $earlyOutcome;
        }

        [$resolved, $pendingApprovals] = $this->withRepairSetting(
            $options,
            fn (): array => $this->resolveToolCalls($result->toolCalls, $tools, $isFinalStep),
        );

        if (count($resolved) > 1 && ($max = $this->toolConcurrency($options)) > 1 && ! $isFinalStep) {
            $results = yield from $this->concurrentToolResults($resolved, $max, function (ToolCall $toolCall, ?Tool $tool) use ($options, $tools, $invocationId, $context): Generator {
                if (! $tool instanceof AgentTool) {
                    return $this->withRepairSetting(
                        $options,
                        fn (): ToolResult => $this->resolvedToolResult($toolCall, $tool, false, $tools, $context),
                    );
                }

                $events = $this->executeAgentToolStreaming($tool, $toolCall->arguments, $toolCall->id, $context);

                yield from $this->preliminaryToolResults($events, $toolCall, $invocationId);

                return $this->recordedToolResult($this->toolResult($toolCall, $events->getReturn()), $context);
            }, $context);

            return [$results, $pendingApprovals];
        }

        $toolResults = [];

        foreach ($resolved as [$toolCall, $tool]) {
            if (! $tool instanceof AgentTool || $isFinalStep) {
                $toolResults[] = $this->withRepairSetting(
                    $options,
                    fn (): ToolResult => $this->resolvedToolResult($toolCall, $tool, $isFinalStep, $tools, $context),
                );

                continue;
            }

            $events = $this->executeAgentToolStreaming($tool, $toolCall->arguments, $toolCall->id, $context);

            yield from $this->preliminaryToolResults($events, $toolCall, $invocationId);

            $toolResults[] = $this->recordedToolResult($this->toolResult($toolCall, $events->getReturn()), $context);
        }

        return [$toolResults, $pendingApprovals];
    }

    /**
     * Report the output a still running tool has produced so far.
     *
     * @param Generator<int, StreamEvent, mixed, string> $events
     * @return Generator<int, ToolResultEvent>
     */
    protected function preliminaryToolResults(Generator $events, ToolCall $toolCall, string $invocationId): Generator
    {
        $deltas = [];
        $written = 0;
        $reportedAt = 0;

        foreach ($events as $event) {
            if ($event instanceof TextDelta) {
                $deltas[] = $event;
                $written += strlen($event->delta);

                // Each report restates the whole output, so one per delta would grow the stream quadratically...
                if ($written - $reportedAt < self::PRELIMINARY_OUTPUT_BYTES) {
                    continue;
                }
            }

            $reportedAt = $written;

            $result = $this->toolResult($toolCall, TextDelta::combine($deltas));

            yield (new ToolResultEvent(
                $this->generateEventId(),
                $result,
                $result->successful(),
                $result->error(),
                time(),
                preliminary: true,
            ))->withInvocationId($invocationId);
        }
    }

    /**
     * Execute a sub-agent tool, streaming its activity while reporting through the run context.
     *
     * @return Generator<int, StreamEvent, mixed, string>
     */
    protected function executeAgentToolStreaming(AgentTool $tool, array $arguments, ?string $toolCallId = null, ?RunContext $context = null): Generator
    {
        $toolInvocationId = (string) Str::uuid7();
        $parentInvocationId = $context?->invocationId;

        $context?->invokingTool($tool, $arguments, $toolInvocationId);

        $startedAt = hrtime(true);

        $events = $tool->stream(new Request($arguments, $toolCallId, $toolInvocationId));

        // Advanced by hand so every resumption of the child run, not only the first, sees this tool call as its parent...
        while (ParentInvocation::within($parentInvocationId, $toolInvocationId, fn (): bool => $events->valid())) {
            yield $events->current();

            ParentInvocation::within($parentInvocationId, $toolInvocationId, function () use ($events): void {
                $events->next();
            });
        }

        $result = (string) $events->getReturn();

        $context?->toolInvoked($tool, $arguments, $result, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

        return $result;
    }

    /**
     * Run the executable tools and collect any pending approvals.
     *
     * @param ToolCall[] $toolCalls
     * @param array<ProviderTool|Tool> $tools
     * @return array{array<int, ToolResult>, Collection<int, PendingApproval>}
     */
    protected function approvalAwareToolResults(array $toolCalls, array $tools, bool $isFinalStep = false, ?RunContext $context = null): array
    {
        [$resolved, $pendingApprovals] = $this->resolveToolCalls($toolCalls, $tools, $isFinalStep);

        if (count($resolved) > 1 && ($max = CoroutineContext::get(self::TOOL_CONCURRENCY_CONTEXT_KEY, 1)) > 1 && ! $isFinalStep) {
            $execution = $this->concurrentToolResults(
                $resolved,
                $max,
                fn (ToolCall $toolCall, ?Tool $tool): ToolResult => $this->resolvedToolResult($toolCall, $tool, false, $tools, $context),
                $context,
            );

            iterator_to_array($execution, false);

            return [$execution->getReturn(), $pendingApprovals];
        }

        $toolResults = array_map(
            fn (array $pair): ToolResult => $this->resolvedToolResult($pair[0], $pair[1], $isFinalStep, $tools, $context),
            $resolved,
        );

        return [$toolResults, $pendingApprovals];
    }

    /**
     * Execute a bounded group of tools, forwarding live events and retaining result order.
     *
     * @param array<int, array{ToolCall, ?Tool}> $resolved
     * @param Closure(ToolCall, ?Tool): (Generator<int, ToolResultEvent, mixed, ToolResult>|ToolResult) $execute
     * @return Generator<int, ToolResultEvent, mixed, array<int, ToolResult>>
     */
    private function concurrentToolResults(array $resolved, int $max, Closure $execute, ?RunContext $context): Generator
    {
        $runner = $context?->contextRunner() ?? Ai::captureContext();
        DB::releaseIdleConnections();

        $count = count($resolved);
        $concurrent = new WaitConcurrent($max = min($max, $count));
        /** @var Channel<ToolResultEvent> $events */
        $events = new Channel($max);
        $next = 0;
        $completed = 0;
        $results = [];
        $failure = null;

        try {
            for ($worker = 0; $worker < $max && $next < $count && $failure === null; ++$worker) {
                $concurrent->fork(function () use ($resolved, $execute, $runner, $count, $events, &$next, &$completed, &$results, &$failure): void {
                    $work = function () use ($resolved, $execute, $count, $events, &$next, &$completed, &$results, &$failure): void {
                        while ($next < $count && $failure === null && ! $events->isClosing()) {
                            // Reserve before invoking user code, which may suspend immediately.
                            $index = $next++;
                            $result = $execute(...$resolved[$index]);

                            if ($result instanceof Generator) {
                                foreach ($result as $event) {
                                    if (! $events->push($event)) {
                                        if ($events->isCanceled()) {
                                            throw new CanceledException('Forwarding tool output was canceled.');
                                        }

                                        return;
                                    }
                                }

                                $result = $result->getReturn();
                            }

                            $results[$index] = $result;

                            if (++$completed === $count) {
                                $events->close();
                            }
                        }
                    };

                    try {
                        $runner === null ? $work() : $runner($work);
                    } catch (Throwable $exception) {
                        $failure ??= $exception;
                        $events->close();
                    }
                }, [self::REPAIR_TOOL_CALLS_CONTEXT_KEY, ParentInvocation::PARENT_INVOCATION_CONTEXT_KEY, AiManager::ON_DEMAND_PROVIDERS_CONTEXT_KEY]);
            }

            while ($failure === null) {
                $event = $events->pop();

                if ($event === false) {
                    if ($events->isCanceled()) {
                        throw new CanceledException('Waiting for tool output was canceled.');
                    }

                    break;
                }

                yield $event;
            }

            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            $events->close();
            $concurrent->cancel();
            $concurrent->wait();
        }

        ksort($results);

        return $results;
    }

    /**
     * Execute the resolved tool call, or mark it as repaired or exhausted when it can no longer run.
     *
     * @param array<ProviderTool|Tool> $tools
     */
    protected function resolvedToolResult(ToolCall $toolCall, ?Tool $tool, bool $isFinalStep, array $tools = [], ?RunContext $context = null): ToolResult
    {
        return $this->recordedToolResult($this->toolResult(
            $toolCall,
            match (true) {
                ! $tool instanceof Tool && CoroutineContext::get(self::REPAIR_TOOL_CALLS_CONTEXT_KEY, false) => "Tool '{$toolCall->name}' does not exist. Available tools: {$this->availableToolNames($tools)}.",
                $isFinalStep => 'The agent reached its maximum number of steps without running this tool call.',
                default => $this->executeTool($tool, $toolCall->arguments, $toolCall->id, $context),
            },
            failed: ! $tool instanceof Tool || $isFinalStep,
        ), $context);
    }

    /**
     * Answer the recorded step with the result its tool just produced.
     */
    protected function recordedToolResult(ToolResult $result, ?RunContext $context): ToolResult
    {
        $context?->recordToolResult($result);

        return $result;
    }

    /**
     * Create a tool result for the given tool call.
     */
    protected function toolResult(ToolCall $toolCall, mixed $result, bool $failed = false): ToolResult
    {
        return new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            $result,
            $toolCall->resultId,
            failed: $failed,
        );
    }

    /**
     * Split the step's tool calls into executable [ToolCall, Tool] pairs and pending approvals.
     *
     * @param ToolCall[] $toolCalls
     * @param Tool[] $tools
     * @return array{array<int, array{ToolCall, ?Tool}>, Collection<int, PendingApproval>}
     */
    protected function resolveToolCalls(array $toolCalls, array $tools, bool $isFinalStep): array
    {
        $pendingApprovals = collect();
        $resolved = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            $approval = $tool instanceof Tool ? $this->approvalForTool($tool, $toolCall) : null;

            if ($approval instanceof Approval) {
                $pendingApprovals->push(new PendingApproval(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $approval->reason,
                ));

                continue;
            }

            if (! $tool instanceof Tool && ! $isFinalStep && ! CoroutineContext::get(self::REPAIR_TOOL_CALLS_CONTEXT_KEY, false)) {
                throw new NoSuchToolException($toolCall->name);
            }

            $resolved[] = [$toolCall, $tool];
        }

        return [$resolved, $pendingApprovals];
    }

    /**
     * The names of the locally executable tools, as advertised back to a model that called an unknown one.
     *
     * @param array<ProviderTool|Tool> $tools
     */
    protected function availableToolNames(array $tools): string
    {
        return implode(', ', array_map(
            ToolNameResolver::resolve(...),
            array_filter($tools, fn (mixed $tool): bool => $tool instanceof Tool),
        )) ?: 'none';
    }

    /**
     * Apply the approval's decisions to the pending pause, returning the updated history and resume state.
     *
     * @param array<string, Decision> $approval
     * @param Message[] $messages
     * @param array<ProviderTool|Tool> $tools
     * @param null|array{Collection<int, ToolCall>, Collection<string, ?Tool>} $validatedApproval a caller's own eager validateApproval() result, reused instead of re-validating
     */
    protected function resumeFromApproval(array $approval, array $messages, array $tools, ?array $validatedApproval = null, ?RunContext $context = null): ApprovalResumption
    {
        $messages = $this->settleAbandonedToolCalls($messages, exceptLatestAssistantTurn: true);

        [$approvalResults, $shouldContinue] = $this->resolveApprovalResults($approval, $messages, $tools, $validatedApproval, $context);

        $newMessages = [];

        if (filled($approvalResults)) {
            [$messages, $newMessages] = $this->appendApprovalResults($messages, $approvalResults);
        }

        return new ApprovalResumption(
            messages: $messages,
            newMessages: $newMessages,
            results: $approvalResults,
            shouldContinue: $shouldContinue,
        );
    }

    /**
     * Claim the pending calls and record each decision's outcome before executing the next.
     *
     * @param array<string, Decision> $approval
     * @param Message[] $messages
     * @param array<ProviderTool|Tool> $tools
     * @param null|array{Collection<int, ToolCall>, Collection<string, ?Tool>} $validatedApproval a caller's own eager validateApproval() result, reused instead of re-validating
     * @return array{array<int, ToolResult>, bool}
     */
    protected function resolveApprovalResults(array $approval, array $messages, array $tools, ?array $validatedApproval = null, ?RunContext $context = null): array
    {
        [$pendingToolCalls, $resolvedTools] = $validatedApproval ?? $this->validateApproval($approval, $messages, $tools);

        $context?->claimPendingApprovals($pendingToolCalls->pluck('id')->all());

        $toolResults = [];
        $hasBareRejection = false;

        foreach ($pendingToolCalls as $toolCall) {
            $tool = $resolvedTools[$toolCall->id];

            $decision = $tool instanceof Tool && ! $tool instanceof Approvable
                ? Decision::reject('This tool call was not executed because it was not pending approval.')
                : $approval[$toolCall->id] ?? $approval['*'] ?? Decision::reject('The user rejected this tool call.');

            if ($decision->isRejected()) {
                $hasBareRejection = $hasBareRejection || $decision->result === null;

                $toolResults[] = $result = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    $decision->result ?? 'The user rejected this tool call.',
                    $toolCall->resultId,
                    denied: true,
                );

                $context?->recordApprovalResult($result);

                continue;
            }

            $arguments = $decision->arguments ?? $toolCall->arguments;

            if (! $tool instanceof Tool) {
                throw new NoSuchToolException($toolCall->name);
            }

            $failed = false;

            try {
                $result = $this->executeTool($tool, $arguments, $toolCall->id, $context);
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $failed = true;
                $result = 'The tool call failed: ' . $exception->getMessage();
            }

            $toolResults[] = $result = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $arguments,
                $result,
                $toolCall->resultId,
                failed: $failed,
            );

            $context?->recordApprovalResult($result);
        }

        return [$toolResults, ! $hasBareRejection];
    }

    /**
     * Validate the approval's decisions against the pending tool calls, throwing on any mismatch.
     *
     * @param array<string, Decision> $approval
     * @param Message[] $messages
     * @param array<ProviderTool|Tool> $tools
     * @return array{Collection<int, ToolCall>, Collection<string, ?Tool>}
     */
    public function validateApproval(array $approval, array $messages, array $tools): array
    {
        [$pendingToolCalls, $resolvedToolCallIds] = $this->pendingToolCalls($messages);

        $pendingIds = $pendingToolCalls->pluck('id');

        $decisionIds = collect(array_keys($approval))->reject(
            fn (int|string $id): bool => $id === '*',
        );

        $resolvedTools = $pendingToolCalls->mapWithKeys(fn (ToolCall $toolCall): array => [
            $toolCall->id => $this->findTool($toolCall->name, $tools),
        ]);

        $approvals = $pendingToolCalls->mapWithKeys(fn (ToolCall $toolCall): array => [
            $toolCall->id => $this->approvalForTool($resolvedTools[$toolCall->id], $toolCall),
        ]);

        $gated = $pendingToolCalls->filter(
            fn (ToolCall $toolCall): bool => $approvals[$toolCall->id] instanceof Approval
        );

        $unknown = $decisionIds->diff($pendingIds);

        $missing = array_key_exists('*', $approval)
            ? collect()
            : $gated->pluck('id')->diff($decisionIds);

        if ($unknown->isNotEmpty() || $missing->isNotEmpty()) {
            $message = $unknown->intersect($resolvedToolCallIds)->isNotEmpty()
                ? 'Approval decisions include already-resolved tool call ids.'
                : 'Approval decisions do not match the pending tool calls.';

            throw new ApprovalMismatchException($message, $this->pendingApprovalsFor($gated, $approvals));
        }

        if ($pendingToolCalls->isEmpty()) {
            throw new ApprovalMismatchException('There are no tool calls pending approval.', collect());
        }

        return [$pendingToolCalls, $resolvedTools];
    }

    /**
     * Resolve the approval requirement for a tool call, or null when it is not gated.
     */
    protected function approvalForTool(?Tool $tool, ToolCall $toolCall): ?Approval
    {
        return $tool instanceof Approvable
            ? $tool->shouldRequestApproval(new Request($toolCall->arguments, $toolCall->id))
            : null;
    }

    /**
     * Generate a unique stream event ID.
     */
    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }

    /**
     * Build an assistant message from the given step response.
     */
    protected function buildAssistantMessage(StepResponse $result): AssistantMessage
    {
        return new AssistantMessage(
            $result->text,
            collect($result->toolCalls),
            $result->replayBlocks,
        );
    }

    /**
     * Build the recorded step from its provider response.
     */
    protected function buildStep(StepResponse $result): Step
    {
        return (new Step(
            $result->text,
            $result->toolCalls,
            [],
            $result->finishReason,
            $result->usage,
            $result->meta,
            $result->reasoning,
            $result->replayBlocks,
            $result->providerToolCalls,
        ))->withRawResponse($result->raw);
    }

    /**
     * Build the final text response from all generated steps.
     */
    protected function buildFinalResponse(
        Collection $steps,
        array $newMessages,
        ?StepResponse $lastResult,
    ): TextResponse {
        $finalStep = $steps->last();

        $reasoningText = static::joinReasoning($steps->pluck('reasoning'));

        $totalUsage = $steps->reduce(
            fn (TextUsage $carry, Step $step): TextUsage => $carry->add($step->usage),
            new TextUsage,
        );

        $newMessages = collect($newMessages)->values();

        if ($lastResult?->structured !== null) {
            return (new StructuredTextResponse(
                $lastResult->structured,
                $finalStep->text,
                $totalUsage,
                $finalStep->meta,
            ))->withMessages($newMessages)->withToolCallsAndResults(
                toolCalls: $steps->flatMap(fn (Step $s): array => $s->toolCalls),
                toolResults: $newMessages
                    ->whereInstanceOf(ToolResultMessage::class)
                    ->flatMap(fn (ToolResultMessage $message): Collection => $message->toolResults),
            )->withSteps($steps)->withReasoning($reasoningText)->withRawResponse($lastResult->raw);
        }

        return (new TextResponse(
            $finalStep->text,
            $totalUsage,
            $finalStep->meta,
        ))->withMessages($newMessages)->withSteps($steps)->withReasoning($reasoningText)->withRawResponse($lastResult?->raw);
    }

    /**
     * Ensure at most one tool search wrapper is registered per request.
     *
     * @param Tool[] $tools
     */
    protected function ensureSingleToolSearch(array $tools): void
    {
        if (count(array_filter($tools, fn (mixed $tool): bool => $tool instanceof ToolSearch)) > 1) {
            throw new LogicException('Only a single tool search wrapper may be registered per request.');
        }
    }

    /**
     * Drop provider tools the provider cannot run, unwrapping tool search into its deferred tools.
     *
     * @param array<ProviderTool|Tool> $tools
     * @return array<ProviderTool|Tool>
     */
    protected function toolsSupportedBy(TextProvider $provider, array $tools): array
    {
        return (new Collection($tools))->flatMap(fn (mixed $tool): array => match (true) {
            $tool instanceof CodeExecution => $provider instanceof SupportsCodeExecution ? [$tool] : [],
            $tool instanceof FileSearch => $provider instanceof SupportsFileSearch ? [$tool] : [],
            $tool instanceof ToolSearch => $provider instanceof SupportsToolSearch ? [$tool] : $tool->tools,
            $tool instanceof WebFetch => $provider instanceof SupportsWebFetch ? [$tool] : [],
            $tool instanceof WebSearch => $provider instanceof SupportsWebSearch ? [$tool] : [],
            $tool instanceof ProviderTool => [],
            default => [$tool],
        })->all();
    }
}
