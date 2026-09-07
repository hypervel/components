<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\Support\ExceptionContextRegistry;
use Hypervel\OpenTelemetry\Support\OperationOrigin;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\RequestTelemetryState;
use Hypervel\OpenTelemetry\Support\UserContextResolver;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Exception\StackTraceFormatter;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ExceptionAttributes;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ExceptionInstrumentation extends AbstractInstrumentation
{
    use LogsMessagesTrait;

    protected const string EXCEPTIONS_METRIC = 'hypervel.exceptions';

    protected bool $message = false;

    protected bool $stackTrace = false;

    protected bool $userContext = false;

    protected ?LoggerInterface $logger = null;

    protected ?CounterInterface $exceptions = null;

    /**
     * Create exception instrumentation.
     */
    public function __construct(
        protected Container $container,
        protected LoggerProviderInterface $loggerProvider,
        protected MeterProviderInterface $meterProvider,
        protected ClockInterface $clock,
        protected OpenTelemetryManager $openTelemetry,
        protected ExceptionContextRegistry $exceptionContexts,
        protected OperationOrigin $origins,
        protected ProcessIdentity $identity,
        protected UserContextResolver $userContexts,
    ) {
    }

    /**
     * Register direct exception recording.
     */
    protected function registerInstrumentation(): void
    {
        $logsEnabled = $this->options->enabled('logs');

        if ($logsEnabled) {
            $this->logger = $this->loggerProvider->getLogger('hypervel.exceptions');
            $this->message = $this->options->enabled('message');
            $this->stackTrace = $this->options->enabled('stack_trace');
            $this->exceptionContexts->enable();

            $httpOptions = $this->openTelemetry->configuration()['instrumentation'][HttpServerInstrumentation::class] ?? false;
            $this->userContext = is_array($httpOptions) && ($httpOptions['user_context'] ?? false);
        }

        if ($this->metricEnabled(self::EXCEPTIONS_METRIC)) {
            $this->exceptions = $this->meterProvider
                ->getMeter('hypervel.exceptions')
                ->createCounter(
                    self::EXCEPTIONS_METRIC,
                    '{exception}',
                    'Number of reported exceptions.',
                );
        }

        $register = function (ExceptionHandler $handler) use ($logsEnabled): void {
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $exception): void {
                $this->record($exception);
            });

            if ($logsEnabled) {
                $this->exceptionContexts->markRecorderRegistered();
            }
        };

        $this->container->afterResolving(ExceptionHandler::class, $register);

        if ($this->container->resolved(ExceptionHandler::class)) {
            $register($this->container->make(ExceptionHandler::class));
        }
    }

    /**
     * Record one reported exception.
     */
    protected function record(Throwable $exception): void
    {
        $currentContext = Context::getCurrent();
        $context = null;
        $origin = null;

        if ($this->logger !== null) {
            $handoff = $this->exceptionContexts->take($exception);

            if ($handoff !== null) {
                $context = $handoff->context;
                $origin = $handoff->origin;
            } elseif (Span::fromContext($currentContext)->getContext()->isValid()) {
                $context = $currentContext;
            } elseif (($requestContext = RequestTelemetryState::current()?->context) !== null
                && Span::fromContext($requestContext)->getContext()->isValid()
            ) {
                $context = $requestContext;
            }
        }

        $origin ??= $this->origins->resolve($context ?? $currentContext, $this->identity);
        $metricAttributes = array_filter([
            ExceptionAttributes::EXCEPTION_TYPE => $exception::class,
            'hypervel.exception.origin' => $origin,
        ], static fn (mixed $value): bool => $value !== null);

        $this->exceptions?->add(1, $metricAttributes);

        if ($this->logger === null) {
            return;
        }

        $builder = $this->logger
            ->logRecordBuilder()
            ->setTimestamp($this->clock->now())
            ->setSeverityNumber(Severity::ERROR)
            ->setSeverityText('ERROR')
            ->setEventName('exception')
            ->setBody($this->message && $exception->getMessage() !== ''
                ? $exception->getMessage()
                : $exception::class)
            ->setAttributes($metricAttributes);

        if ($context !== null) {
            $builder->setContext($context);
        }

        if ($this->message && $this->stackTrace) {
            $builder->setException($exception);
        } else {
            if ($this->message) {
                $builder->setAttribute(ExceptionAttributes::EXCEPTION_MESSAGE, $exception->getMessage());
            }

            if ($this->stackTrace) {
                $builder->setAttribute(
                    ExceptionAttributes::EXCEPTION_STACKTRACE,
                    StackTraceFormatter::format($exception),
                );
            }
        }

        if ($exception->getFile() !== '') {
            $builder->setAttribute(CodeAttributes::CODE_FILE_PATH, $exception->getFile());
        }

        if ($exception->getLine() > 0) {
            $builder->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $exception->getLine());
        }

        $frame = $exception->getTrace()[0] ?? null;

        if (is_array($frame) && is_string($frame['function'] ?? null)) {
            $function = is_string($frame['class'] ?? null)
                ? $frame['class'] . '::' . $frame['function']
                : $frame['function'];
            $builder->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, $function);
        }

        if ($origin === OperationOrigin::REQUEST
            && $this->userContext
            && ($requestState = RequestTelemetryState::current()) !== null
        ) {
            $builder->setAttributes($this->userContexts->resolve($requestState));
        }

        foreach ($this->openTelemetry->exceptionEnrichers() as $enricher) {
            try {
                $enricher($builder, $exception);
            } catch (CanceledException $cancellation) {
                throw $cancellation;
            } catch (Throwable $throwable) {
                self::logError('OpenTelemetry exception enrichment failed.', ['exception' => $throwable]);
            }
        }

        $builder->emit();
    }
}
