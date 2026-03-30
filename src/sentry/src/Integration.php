<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Context\CoroutineContext;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Routing\Route;
use Hypervel\Sentry\Integration\ModelViolations as ModelViolationReports;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\ExceptionMechanism;
use Sentry\Integration\IntegrationInterface;
use Sentry\Logs\Logs;
use Sentry\Metrics\TraceMetrics;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Tracing\TransactionSource;
use Throwable;

use function Sentry\addBreadcrumb;
use function Sentry\configureScope;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

use const SWOOLE_VERSION;

class Integration implements IntegrationInterface
{
    private const CONTEXT_TRANSACTION_KEY = '__sentry.transaction';

    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(static function (Event $event): Event {
            $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

            if (! $self instanceof self) {
                return $event;
            }

            if (defined('\SWOOLE_VERSION')) {
                $event->setContext('swoole', [
                    'version' => SWOOLE_VERSION,
                ]);
            }

            if (empty($event->getTransaction())) {
                $event->setTransaction(self::getTransaction());
            }

            return $event;
        });
    }

    /**
     * Register the exception handler with the Hypervel exception configuration.
     */
    public static function handles(Exceptions $exceptions): void
    {
        $exceptions->reportable(static function (Throwable $exception) {
            self::captureUnhandledException($exception);
        });
    }

    /**
     * Add a breadcrumb if the integration is enabled.
     */
    public static function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

        if (! $self instanceof self) {
            return;
        }

        addBreadcrumb($breadcrumb);
    }

    /**
     * Configure the scope if the integration is enabled.
     */
    public static function configureScope(callable $callback): void
    {
        $self = SentrySdk::getCurrentHub()->getIntegration(self::class);

        if (! $self instanceof self) {
            return;
        }

        configureScope($callback);
    }

    /**
     * Get the current transaction name from coroutine-local storage.
     */
    public static function getTransaction(): ?string
    {
        return CoroutineContext::get(self::CONTEXT_TRANSACTION_KEY);
    }

    /**
     * Set the current transaction name in coroutine-local storage.
     */
    public static function setTransaction(?string $transaction): void
    {
        CoroutineContext::set(self::CONTEXT_TRANSACTION_KEY, $transaction);
    }

    /**
     * Block until all events are processed by the PHP SDK client.
     *
     * @internal this is not part of the public API and is here temporarily until
     *  the underlying issue can be resolved, this method will be removed
     */
    public static function flushEvents(): void
    {
        $client = SentrySdk::getCurrentHub()->getClient();

        if ($client !== null) {
            $client->flush();

            Logs::getInstance()->flush();
            TraceMetrics::getInstance()->flush();
        }
    }

    /**
     * Extract the readable name and transaction source for a route.
     *
     * @return array{0: string, 1: TransactionSource}
     *
     * @internal this helper is used in various places to extract meaningful info from a Route object
     */
    public static function extractNameAndSourceForRoute(Route $route): array
    {
        return [
            '/' . ltrim($route->uri(), '/'),
            TransactionSource::route(),
        ];
    }

    /**
     * Retrieve the meta tags with tracing information to link this request to front-end requests.
     * This propagates the Dynamic Sampling Context.
     */
    public static function sentryMeta(): string
    {
        return self::sentryTracingMeta() . self::sentryBaggageMeta();
    }

    /**
     * Retrieve the `sentry-trace` meta tag with tracing information to link this request to front-end requests.
     */
    public static function sentryTracingMeta(): string
    {
        return sprintf('<meta name="sentry-trace" content="%s"/>', self::escapeMetaTagContent(getTraceparent()));
    }

    /**
     * Retrieve the `baggage` meta tag with information to link this request to front-end requests.
     * This propagates the Dynamic Sampling Context.
     */
    public static function sentryBaggageMeta(): string
    {
        return sprintf('<meta name="baggage" content="%s"/>', self::escapeMetaTagContent(getBaggage()));
    }

    /**
     * Capture an unhandled exception and report it to Sentry.
     */
    public static function captureUnhandledException(Throwable $throwable): ?EventId
    {
        // We instruct users to call `captureUnhandledException` in their exception handler, however this does not mean
        // the exception was actually unhandled. Hypervel has the `report` helper function that is used to report to a log
        // file or Sentry, but that means they are handled otherwise they wouldn't have been routed through `report`. So to
        // prevent marking those as "unhandled" we try and make an educated guess if the call to `captureUnhandledException`
        // came from the `report` helper and shouldn't be marked as "unhandled" even though they come to us here to be reported
        $handled = self::makeAnEducatedGuessIfTheExceptionMaybeWasHandled();

        $hint = EventHint::fromArray([
            'mechanism' => new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, $handled),
        ]);

        return SentrySdk::getCurrentHub()->captureException($throwable, $hint);
    }

    /**
     * Return a callback for `Model::handleMissingAttributeViolationUsing` to report missing attribute violations to Sentry.
     */
    public static function missingAttributeViolationReporter(?callable $callback = null, bool $suppressDuplicateReports = true, bool $reportAfterResponse = true): callable
    {
        return new ModelViolationReports\MissingAttributeModelViolationReporter($callback, $suppressDuplicateReports, $reportAfterResponse);
    }

    /**
     * Return a callback for `Model::handleLazyLoadingViolationUsing` to report lazy loading violations to Sentry.
     */
    public static function lazyLoadingViolationReporter(?callable $callback = null, bool $suppressDuplicateReports = true, bool $reportAfterResponse = true): callable
    {
        return new ModelViolationReports\LazyLoadingModelViolationReporter($callback, $suppressDuplicateReports, $reportAfterResponse);
    }

    /**
     * Return a callback for `Model::handleDiscardedAttributeViolationUsing` to report discarded attribute violations to Sentry.
     */
    public static function discardedAttributeViolationReporter(?callable $callback = null, bool $suppressDuplicateReports = true, bool $reportAfterResponse = true): callable
    {
        return new ModelViolationReports\DiscardedAttributeViolationReporter($callback, $suppressDuplicateReports, $reportAfterResponse);
    }

    /**
     * Escape a value for safe use in an HTML meta tag content attribute.
     */
    private static function escapeMetaTagContent(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Try to make an educated guess if the call came from the `report` helper.
     *
     * @see https://github.com/laravel/framework/blob/008a4dd49c3a13343137d2bc43297e62006c7f29/src/Illuminate/Foundation/helpers.php#L667-L682
     */
    private static function makeAnEducatedGuessIfTheExceptionMaybeWasHandled(): bool
    {
        // We limit the amount of backtrace frames since it is very unlikely to be any deeper
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        // We are looking for `$handler->report()` to be called from the `report()` function
        foreach ($trace as $frameIndex => $frame) {
            // We need a frame with a class defined, we can skip top-level function frames
            if (! isset($frame['class'])) {
                continue;
            }

            // Check if the frame was indeed `$handler->report()`
            if ($frame['type'] !== '->' || $frame['function'] !== 'report') {
                continue;
            }

            // Make sure we have a next frame, we could have reached the end of the trace
            if (! isset($trace[$frameIndex + 1])) {
                continue;
            }

            // The next frame should contain the call to the `report()` helper function
            $nextFrame = $trace[$frameIndex + 1];

            // If a class was set or the function name is not `report` we can skip this frame
            if (isset($nextFrame['class']) || $nextFrame['function'] !== 'report') {
                continue;
            }

            // If we reached this point we can be pretty sure the `report` function was called
            // and we can come to the educated conclusion the exception was indeed handled
            return true;
        }

        // If we reached this point we can be pretty sure the `report` function was not called
        return false;
    }
}
