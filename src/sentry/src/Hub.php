<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Context\ApplicationContext;
use Hypervel\Context\Context;
use Psr\Log\NullLogger;
use Sentry\Breadcrumb;
use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\Integration\IntegrationInterface;
use Sentry\MonitorConfig;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Sentry\State\Layer;
use Sentry\State\Scope;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Throwable;

use function sprintf;

class Hub implements HubInterface
{
    public const CONTEXT_STACK_KEY = 'sentry.stack';

    public const CONTEXT_LAST_EVENT_ID_KEY = 'sentry.last_event_id';

    public const CONTEXT_REQUEST_COROUTINE_ID_KEY = 'sentry.coroutine_id';

    public function __construct(protected ?ClientInterface $client = null, protected ?Scope $scope = null)
    {
    }

    public function getClient(): ?ClientInterface
    {
        return $this->client ?? ApplicationContext::getContainer()->get(ClientInterface::class);
    }

    public function bindClient(ClientInterface $client): void
    {
        $this->client = $client;
    }

    public function getLastEventId(): ?EventId
    {
        return Context::get(static::CONTEXT_LAST_EVENT_ID_KEY);
    }

    public function pushScope(): Scope
    {
        $clonedScope = clone $this->getScope();
        Context::override(static::CONTEXT_STACK_KEY, function (array $layers) use ($clonedScope) {
            $currentLayers[] = new Layer($this->getClient(), $clonedScope);

            return $currentLayers;
        });

        return $clonedScope;
    }

    public function popScope(): bool
    {
        $currentLayers = Context::get(static::CONTEXT_STACK_KEY, []);
        if (count($currentLayers) === 1) {
            return false; // Cannot pop the last scope, as it would leave no layers in the stack
        }

        array_pop($currentLayers);
        Context::set(static::CONTEXT_STACK_KEY, $currentLayers);

        return true;
    }

    public function captureMessage(string $message, ?Severity $level = null, ?EventHint $hint = null): ?EventId
    {
        $client = $this->getClient();

        if ($client !== null) {
            return Context::set(
                static::CONTEXT_LAST_EVENT_ID_KEY,
                $client->captureMessage($message, $level, $this->getScope(), $hint)
            );
        }

        return null;
    }

    public function captureException(Throwable $exception, ?EventHint $hint = null): ?EventId
    {
        $client = $this->getClient();

        if ($client !== null) {
            return Context::set(
                static::CONTEXT_LAST_EVENT_ID_KEY,
                $client->captureException($exception, $this->getScope(), $hint)
            );
        }

        return null;
    }

    public function captureEvent(Event $event, ?EventHint $hint = null): ?EventId
    {
        $client = $this->getClient();

        if ($client !== null) {
            return Context::set(
                static::CONTEXT_LAST_EVENT_ID_KEY,
                $client->captureEvent($event, $hint, $this->getScope())
            );
        }

        return null;
    }

    public function captureLastError(?EventHint $hint = null): ?EventId
    {
        $client = $this->getClient();

        if ($client !== null) {
            return Context::set(static::CONTEXT_LAST_EVENT_ID_KEY, $client->captureLastError($this->getScope(), $hint));
        }

        return null;
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): bool
    {
        $client = $this->getClient();

        if ($client === null) {
            return false;
        }

        $options = $client->getOptions();
        $beforeBreadcrumbCallback = $options->getBeforeBreadcrumbCallback();
        $maxBreadcrumbs = $options->getMaxBreadcrumbs();

        if ($maxBreadcrumbs <= 0) {
            return false;
        }

        $breadcrumb = $beforeBreadcrumbCallback($breadcrumb);

        if ($breadcrumb !== null) {
            $scope = $this->getScope();
            $scope->addBreadcrumb($breadcrumb, $maxBreadcrumbs);
        }

        return $breadcrumb !== null;
    }

    public function configureScope(callable $callback): void
    {
        $callback($this->getScope());
    }

    /**
     * @param array<string, mixed> $customSamplingContext Additional context that will be passed to the
     *                                                    {@see SamplingContext}
     */
    public function startTransaction(TransactionContext $context, array $customSamplingContext = []): Transaction
    {
        $transaction = new Transaction($context, $this);
        $client = $this->getClient();
        $options = $client !== null ? $client->getOptions() : null;
        $logger = $options !== null ? $options->getLoggerOrNullLogger() : new NullLogger();

        if ($options === null || ! $options->isTracingEnabled()) {
            $transaction->setSampled(false);

            $logger->warning(
                sprintf(
                    'Transaction [%s] was started but tracing is not enabled.',
                    (string) $transaction->getTraceId()
                ),
                ['context' => $context]
            );

            return $transaction;
        }

        $samplingContext = SamplingContext::getDefault($context);
        $samplingContext->setAdditionalContext($customSamplingContext);

        $sampleSource = 'context';
        $sampleRand = $context->getMetadata()->getSampleRand();

        if ($transaction->getSampled() === null) {
            $tracesSampler = $options->getTracesSampler();

            if ($tracesSampler !== null) {
                $sampleRate = $tracesSampler($samplingContext);
                $sampleSource = 'config:traces_sampler';
            } else {
                $parentSampleRate = $context->getMetadata()->getParentSamplingRate();
                if ($parentSampleRate !== null) {
                    $sampleRate = $parentSampleRate;
                    $sampleSource = 'parent:sample_rate';
                } else {
                    $sampleRate = $this->getSampleRate(
                        $samplingContext->getParentSampled(),
                        $options->getTracesSampleRate() ?? 0
                    );
                    $sampleSource = $samplingContext->getParentSampled(
                    ) !== null ? 'parent:sampling_decision' : 'config:traces_sample_rate';
                }
            }

            if (! $this->isValidSampleRate($sampleRate)) {
                $transaction->setSampled(false);

                $logger->warning(
                    sprintf(
                        'Transaction [%s] was started but not sampled because sample rate (decided by %s) is invalid.',
                        (string) $transaction->getTraceId(),
                        $sampleSource
                    ),
                    ['context' => $context]
                );

                return $transaction;
            }

            $transaction->getMetadata()->setSamplingRate($sampleRate);

            // Always overwrite the sample_rate in the DSC
            $dynamicSamplingContext = $context->getMetadata()->getDynamicSamplingContext();
            if ($dynamicSamplingContext !== null) {
                $dynamicSamplingContext->set('sample_rate', (string) $sampleRate, true);
            }

            if ($sampleRate === 0.0) {
                $transaction->setSampled(false);

                $logger->info(
                    sprintf(
                        'Transaction [%s] was started but not sampled because sample rate (decided by %s) is %s.',
                        (string) $transaction->getTraceId(),
                        $sampleSource,
                        $sampleRate
                    ),
                    ['context' => $context]
                );

                return $transaction;
            }

            $transaction->setSampled($sampleRand < $sampleRate);
        }

        if (! $transaction->getSampled()) {
            $logger->info(
                sprintf(
                    'Transaction [%s] was started but not sampled, decided by %s.',
                    (string) $transaction->getTraceId(),
                    $sampleSource
                ),
                ['context' => $context]
            );

            return $transaction;
        }

        $logger->info(
            sprintf(
                'Transaction [%s] was started and sampled, decided by %s.',
                (string) $transaction->getTraceId(),
                $sampleSource
            ),
            ['context' => $context]
        );

        $transaction->initSpanRecorder();

        $profilesSampleRate = $options->getProfilesSampleRate();
        if ($profilesSampleRate === null) {
            $logger->info(
                sprintf(
                    'Transaction [%s] is not profiling because `profiles_sample_rate` option is not set.',
                    (string) $transaction->getTraceId()
                )
            );
        } elseif ($this->sample($profilesSampleRate)) {
            $logger->info(
                sprintf(
                    'Transaction [%s] started profiling because it was sampled.',
                    (string) $transaction->getTraceId()
                )
            );

            $transaction->initProfiler()->start();
        } else {
            $logger->info(
                sprintf(
                    'Transaction [%s] is not profiling because it was not sampled.',
                    (string) $transaction->getTraceId()
                )
            );
        }

        return $transaction;
    }

    public function getTransaction(): ?Transaction
    {
        return $this->getScope()->getTransaction();
    }

    public function setSpan(?Span $span): HubInterface
    {
        $this->getScope()->setSpan($span);

        return $this;
    }

    public function getSpan(): ?Span
    {
        return $this->getScope()->getSpan();
    }

    public function withScope(callable $callback)
    {
        $scope = $this->pushScope();

        try {
            return $callback($scope);
        } finally {
            $this->popScope();
        }
    }

    /**
     * @param null|float|int $duration
     */
    public function captureCheckIn(
        string $slug,
        CheckInStatus $status,
        $duration = null,
        ?MonitorConfig $monitorConfig = null,
        ?string $checkInId = null
    ): ?string {
        $client = $this->getClient();

        if ($client === null) {
            return null;
        }

        $options = $client->getOptions();
        $event = Event::createCheckIn();
        $checkIn = new CheckIn(
            $slug,
            $status,
            $checkInId,
            $options->getRelease(),
            $options->getEnvironment(),
            $duration,
            $monitorConfig
        );
        $event->setCheckIn($checkIn);
        $this->captureEvent($event);

        return $checkIn->getId();
    }

    public function getIntegration(string $className): ?IntegrationInterface
    {
        $client = $this->getClient();

        return $client?->getIntegration($className);
    }

    protected function getScope(): Scope
    {
        return $this->getStackTop()->getScope();
    }

    /**
     * Gets the topmost client/layer pair in the stack.
     */
    private function getStackTop(): Layer
    {
        $stack = Context::getOrSet(self::CONTEXT_STACK_KEY, function () {
            $scope = $this->scope ?? new Scope();

            return [new Layer($this->getClient(), $scope)];
        });

        return end($stack);
    }

    private function sample(mixed $sampleRate): bool
    {
        if ($sampleRate === 0.0 || $sampleRate === null) {
            return false;
        }

        if ($sampleRate === 1.0) {
            return true;
        }

        return mt_rand(0, mt_getrandmax() - 1) / mt_getrandmax() < $sampleRate;
    }

    private function isValidSampleRate(mixed $sampleRate): bool
    {
        if (! \is_float($sampleRate) && ! \is_int($sampleRate)) {
            return false;
        }

        if ($sampleRate < 0 || $sampleRate > 1) {
            return false;
        }

        return true;
    }

    private function getSampleRate(?bool $hasParentBeenSampled, float $fallbackSampleRate): float
    {
        if ($hasParentBeenSampled === true) {
            return 1.0;
        }

        if ($hasParentBeenSampled === false) {
            return 0.0;
        }

        return $fallbackSampleRate;
    }
}
