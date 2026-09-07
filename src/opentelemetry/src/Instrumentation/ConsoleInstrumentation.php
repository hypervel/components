<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\OpenTelemetry\Support\ConsoleTelemetryState;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\LogContextScopeFactory;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Support\Str;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Swoole\Coroutine\CanceledException;

class ConsoleInstrumentation extends AbstractInstrumentation
{
    protected const string STATE_CONTEXT_KEY = '__opentelemetry.console_states';

    protected const string DURATION_METRIC = 'hypervel.console.command.duration';

    protected const string COMMAND_ATTRIBUTE = 'hypervel.console.command';

    protected const string EXIT_CODE_ATTRIBUTE = 'hypervel.console.exit_code';

    protected const array DURATION_BOUNDARIES = [
        0.005,
        0.01,
        0.025,
        0.05,
        0.075,
        0.1,
        0.25,
        0.5,
        0.75,
        1,
        2.5,
        5,
        7.5,
        10,
    ];

    /** @var list<string> */
    protected array $commands = [];

    /** @var list<string> */
    protected array $excludedCommands = [];

    protected ?TracerInterface $tracer = null;

    protected ?HistogramInterface $duration = null;

    /**
     * Create console instrumentation.
     */
    public function __construct(
        protected Dispatcher $events,
        protected TracerProviderInterface $tracerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected ExceptionContextRegistry $exceptionContexts,
        protected OperationOrigin $origins,
        protected ProcessIdentity $identity,
        protected LogContextScopeFactory $logContextScopes,
    ) {
    }

    /**
     * Register console listeners and instruments.
     */
    protected function registerInstrumentation(): void
    {
        /** @var list<string> $commands */
        $commands = $this->options->get('commands');
        /** @var list<string> $excludedCommands */
        $excludedCommands = $this->options->get('except');

        if ($commands === []) {
            return;
        }

        $this->commands = $commands;
        $this->excludedCommands = $excludedCommands;

        if ($this->tracesEnabled()) {
            $this->tracer = $this->tracerProvider->getTracer('hypervel.console');
        }

        if ($this->metricEnabled(self::DURATION_METRIC)) {
            $this->duration = $this->meterProvider
                ->getMeter('hypervel.console')
                ->createHistogram(
                    self::DURATION_METRIC,
                    's',
                    'Duration of console command executions.',
                    ['ExplicitBucketBoundaries' => self::DURATION_BOUNDARIES],
                );
        }

        if ($this->tracer === null && $this->duration === null) {
            return;
        }

        $this->events->listen(BeforeHandle::class, function (BeforeHandle $event): void {
            $this->start($event);
        });
        $this->events->listen(AfterExecute::class, function (AfterExecute $event): void {
            $this->finish($event);
        });
    }

    /**
     * Start console-command telemetry.
     */
    protected function start(BeforeHandle $event): void
    {
        $command = $event->command->getName();

        if ($command === null || $command === '' || ! $this->shouldRecord($command)) {
            return;
        }

        $startedAt = $this->clock->now();
        $parent = Context::getCurrent();
        $context = $parent;
        $span = null;
        $scope = null;
        $logContextScope = null;
        $attributes = [self::COMMAND_ATTRIBUTE => $command];

        if ($this->tracer !== null) {
            $span = $this->tracer
                ->spanBuilder($command)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setParent($parent)
                ->setStartTimestamp($startedAt)
                ->setAttributes($attributes)
                ->startSpan();
            $context = $this->origins->withOrigin(
                $span->storeInContext($parent),
                OperationOrigin::CONSOLE,
            );
            $scope = $context->activate();
            $logContextScope = $this->logContextScopes->activate($span->getContext());
        }

        /** @var list<ConsoleTelemetryState> $states */
        $states = CoroutineContext::get(self::STATE_CONTEXT_KEY, []);
        $states[] = new ConsoleTelemetryState(
            $event->command,
            $startedAt,
            $context,
            $span,
            $scope,
            $logContextScope,
            $attributes,
        );
        CoroutineContext::set(self::STATE_CONTEXT_KEY, $states);
    }

    /**
     * Complete console-command telemetry.
     */
    protected function finish(AfterExecute $event): void
    {
        if ($event->throwable instanceof CanceledException) {
            return;
        }

        $state = $this->takeState($event->command);

        if ($state === null) {
            return;
        }

        $finishedAt = $this->clock->now();
        $failed = $event->throwable !== null || $event->exitCode !== 0;
        $result = $failed ? 'failure' : 'success';
        $attributes = $state->attributes + [
            'result' => $result,
            self::EXIT_CODE_ATTRIBUTE => $event->exitCode,
        ];

        if ($event->throwable !== null) {
            $attributes[ErrorAttributes::ERROR_TYPE] = $event->throwable::class;
        }

        try {
            if ($state->span?->isRecording()) {
                $state->span->setAttribute('result', $result);
                $state->span->setAttribute(self::EXIT_CODE_ATTRIBUTE, $event->exitCode);

                if ($event->throwable !== null) {
                    $state->span->recordException($event->throwable);
                    $state->span->setAttribute(ErrorAttributes::ERROR_TYPE, $event->throwable::class);
                }

                if ($failed) {
                    $state->span->setStatus(StatusCode::STATUS_ERROR);
                }
            }

            if ($event->throwable !== null && $state->span !== null) {
                $this->exceptionContexts->associate(
                    $event->throwable,
                    $state->context,
                    $this->origins->resolve($state->context, $this->identity),
                );
            }

            $this->duration?->record(
                ($finishedAt - $state->startedAt) / ClockInterface::NANOS_PER_SECOND,
                $attributes,
                $state->context,
            );
        } finally {
            $state->logContextScope?->close();
            $state->scope?->detach();
            $state->span?->end($finishedAt);
        }
    }

    /**
     * Remove and return the matching top console-command state.
     */
    protected function takeState(Command $command): ?ConsoleTelemetryState
    {
        /** @var list<ConsoleTelemetryState> $states */
        $states = CoroutineContext::get(self::STATE_CONTEXT_KEY, []);
        $last = array_key_last($states);

        if ($last === null || $states[$last]->command !== $command) {
            return null;
        }

        $state = array_pop($states);

        if ($states === []) {
            CoroutineContext::forget(self::STATE_CONTEXT_KEY);
        } else {
            CoroutineContext::set(self::STATE_CONTEXT_KEY, $states);
        }

        return $state;
    }

    /**
     * Determine whether a command is included by the configured patterns.
     */
    protected function shouldRecord(string $command): bool
    {
        return Str::is($this->commands, $command)
            && ! Str::is($this->excludedCommands, $command);
    }
}
