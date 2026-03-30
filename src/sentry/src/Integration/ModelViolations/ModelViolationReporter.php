<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Integration\ModelViolations;

use Closure;
use Exception;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Sentry\Features\Concerns\ResolvesEventOrigin;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionMechanism;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;

use function Hypervel\Support\defer;

abstract class ModelViolationReporter
{
    use ResolvesEventOrigin;

    private const CONTEXT_REPORTED_PREFIX = '__sentry.model_violations.reported.';

    public function __construct(
        private ?Closure $callback,
        private readonly bool $suppressDuplicateReports,
        private readonly bool $reportAfterResponse,
    ) {
    }

    /**
     * Handle a model violation.
     *
     * @param array<int, string>|string $propertyOrProperties
     */
    public function __invoke(Model $model, string|array $propertyOrProperties): void
    {
        $property = is_array($propertyOrProperties)
            ? implode(', ', $propertyOrProperties)
            : $propertyOrProperties;

        if (! $this->shouldReport($model, $property)) {
            return;
        }

        $this->markAsReported($model, $property);

        $origin = $this->resolveEventOrigin();

        if ($this->reportAfterResponse) {
            // defer() instead of app()->terminating() — terminating callbacks
            // accumulate on the process-global Application in Swoole workers.
            defer(function () use ($model, $property, $origin) {
                $this->report($model, $property, $origin);
            }, always: true);
        } else {
            $this->report($model, $property, $origin);
        }
    }

    /**
     * Get the context data for the violation.
     */
    abstract protected function getViolationContext(Model $model, string $property): array;

    /**
     * Get the exception representing the violation.
     */
    abstract protected function getViolationException(Model $model, string $property): Exception;

    /**
     * Determine if the violation should be reported.
     */
    protected function shouldReport(Model $model, string $property): bool
    {
        if (! $this->suppressDuplicateReports) {
            return true;
        }

        /** @var array<string, true> $reported */
        $reported = CoroutineContext::get(self::CONTEXT_REPORTED_PREFIX . static::class, []);

        return ! array_key_exists(get_class($model) . $property, $reported);
    }

    /**
     * Mark a violation as reported.
     */
    protected function markAsReported(Model $model, string $property): void
    {
        if (! $this->suppressDuplicateReports) {
            return;
        }

        $contextKey = self::CONTEXT_REPORTED_PREFIX . static::class;

        /** @var array<string, true> $reported */
        $reported = CoroutineContext::get($contextKey, []);
        $reported[get_class($model) . $property] = true;
        CoroutineContext::set($contextKey, $reported);
    }

    /**
     * Report the violation to Sentry.
     */
    private function report(Model $model, string $property, mixed $origin): void
    {
        SentrySdk::getCurrentHub()->withScope(function (Scope $scope) use ($model, $property, $origin) {
            $scope->setContext('violation', array_merge([
                'model' => get_class($model),
                'origin' => $origin,
            ], $this->getViolationContext($model, $property)));

            SentrySdk::getCurrentHub()->captureEvent(
                tap(Event::createEvent(), static function (Event $event) {
                    $event->setLevel(Severity::warning());
                }),
                EventHint::fromArray([
                    'exception' => $this->getViolationException($model, $property),
                    'mechanism' => new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, true),
                ])
            );
        });

        // Forward the violation to the next handler if there is one
        if ($this->callback !== null) {
            ($this->callback)($model, $property);
        }
    }
}
